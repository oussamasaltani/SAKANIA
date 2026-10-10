<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * The floating Boosthis bubble — the PHP port of
 * {@code lib/boosthis-java/src/app/boosthis/Bubble.java}, and the sibling of the
 * Node ({@code lib/boosthis-runtime-node/src/bubble.ts}), Go, Python and Web
 * bubbles. When the bubble is visible the kit injects a small, self-contained
 * {@code <script>} into outgoing HTML pages that draws a movable badge showing
 * the server's live page pulse — one score, one rating, one request count.
 * Tapping the badge opens a full dashboard-first panel (hero score, rating pill,
 * meter rows) that polls the closed panel read every 2s.
 *
 * PHP DIFFERS from the Java surface in exactly one place, by necessity. Java
 * serves the bubble (and its pulse/panel/account endpoints) through its own
 * {@code HttpExchange} wrapper. PHP has no such handler seam, so the natural
 * surface here is:
 *
 *   - {@link html()}   — the full self-contained markup + styles + script, with
 *                        the pulse/panel/account data inlined so the snippet
 *                        does not depend on any served endpoint;
 *   - {@link inject()} — echoes {@link html()} just before {@code </body>} from
 *                        the host's shutdown path, but ONLY when it is safe:
 *                        the bubble is enabled, the response looks like HTML, the
 *                        kill-switch is not "hidden", and the response is not a
 *                        redirect / download / already-committed body.
 *
 * The pulse/panel/account JSON builders below are kept for parity (identical
 * shape and wording to Java's {@code pulseJson}/{@code panelJson}/
 * {@code accountJson}); {@link inject()} inlines the panel read into the snippet
 * so the panel renders without a network round-trip on a page-only kit.
 *
 * Visibility precedence (first match wins), byte-parity with Java's
 * {@code resolveVisibility}:
 *   1. {@code BOOSTHIS_DISABLED} — the global kill-switch always wins (hidden).
 *   2. {@code BOOSTHIS_BUBBLE} env — recognized on/off values force the answer.
 *   3. Truthy legacy {@code BOOSTHIS_NO_BUBBLE}, then
 *      {@code BOOSTHIS_FORCE_BUBBLE}.
 *   4. The explicit option passed to the caller.
 *   5. Default: VISIBLE. An install that shows nothing in production reads as
 *      broken; hiding is an explicit opt-out.
 *
 * Privacy: display-only. The snippet reads ONLY the closed pulse/panel shapes
 * ({@code {score, rating, sampleCount}} + the closed meter rows) and never
 * transmits anything anywhere. Injection is fail-open: any doubt (non-HTML,
 * compressed, streamed, a non-2xx status, a redirect, missing {@code </body>},
 * or any exception) serves the original bytes untouched — the host is never
 * broken.
 */
final class Bubble
{
    private function __construct()
    {
    }

    /**
     * The closed pulse endpoint path — kept as the canonical constant the panel
     * path derives from, matching the Java {@code PULSE_PATH}.
     */
    public const PULSE_PATH = '/_boosthis/pulse';

    /** The closed panel endpoint path — the coarse dashboard read. */
    public const PANEL_PATH = '/_boosthis/panel';

    /** The closed loopback-only "Connect to your account" auth-context path. */
    public const ACCOUNT_PATH = '/_boosthis/account';

    /**
     * The accepted-terms revision the bubble's first-open gate records/checks.
     * Byte-equal to the canonical TERMS_VERSION constants in the RN/web/server
     * kits. Bump it in lock-step with the others, never alone.
     */
    public const TERMS_VERSION_LITERAL = '2026-07-21';

    /** Length cap for a closed panel caption. */
    private const PANEL_CAPTION_MAX = 120;

    /**
     * The code-defined marker for a registration the SERVER refused for a
     * reason the developer must fix in code. Only this literal ever crosses the
     * closed panel read; the injected snippet holds the display copy.
     */
    public const NOTICE_INSTALL_ID_NOT_UUID = 'install-id-not-uuid';

    /**
     * Whether THIS KIT reports named job runs to Boosthis. A fact about the
     * kit's own surface, not about what this process happens to have run, so
     * an app that has reported nothing yet gets the same honest answer. The
     * cross-runtime guard derives the expected token from
     * lib/background-work-coverage.json.
     */
    public const PANEL_JOBS_REPORTING = 'not-reported';

    // ─── Visibility ──────────────────────────────────────────────────────────

    /**
     * Resolve whether the bubble should be shown. Precedence documented on the
     * class. Never throws.
     *
     * PHP note: {@link RuntimeFlags} exposes the {@code BOOSTHIS_BUBBLE} /
     * {@code BOOSTHIS_NO_BUBBLE} primitives rather than Java's single
     * tri-state reader, so this method composes them into the SAME precedence
     * Java's {@code resolveVisibility} uses.
     */
    public static function resolveVisibility(?bool $option = null): bool
    {
        // Absolute stops are read in their own fail-closed step.
        try {
            if (RuntimeFlags::disabled()
                || KillSwitch::visibleStatus() === KillSwitch::HIDDEN) {
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        }

        // Ordinary read failures deliberately reach the visible default.
        try {
            $raw = RuntimeFlags::env('BOOSTHIS_BUBBLE');
            if ($raw !== null) {
                $directive = strtolower(trim($raw));
                if (in_array($directive, ['1', 'true', 'yes', 'on'], true)) {
                    return true;
                }
                if (in_array($directive, ['0', 'false', 'no', 'off'], true)) {
                    return false;
                }
            }
            if (RuntimeFlags::noBubble()) {
                return false;
            }
            if (RuntimeFlags::forceBubble()) {
                return true;
            }
            if ($option !== null) {
                return $option;
            }
        } catch (\Throwable $e) {
            // fall through
        }

        return true;
    }

    /**
     * Whether the bubble is enabled for THIS response. Mirrors the gating the
     * task requires: visible, and the kill-switch is not silently "hidden".
     * Never throws.
     */
    public static function bubbleEnabled(?bool $option = null): bool
    {
        return self::resolveVisibility($option);
    }

    // ─── Pulse / percentile ──────────────────────────────────────────────────

    /**
     * The closed pulse shape — one score, one rating, one count. Maps the p95
     * request duration onto 0-100 against the shared TTI band, exactly like the
     * sibling bubbles. {@code score}/{@code rating} are null until at least one
     * sample exists.
     *
     * @return array<string,mixed>
     */
    public static function pulseShape(): array
    {
        $durs = Safe::run(static fn (): array => Samples::durations(), []);
        $n = count($durs);
        if ($n === 0) {
            return ['score' => null, 'rating' => null, 'sampleCount' => 0];
        }
        $p95 = self::percentile($durs, 0.95);
        $score = Score::scoreMetric((float) $p95, Thresholds::TTI_GOOD, Thresholds::TTI_POOR);

        return [
            'score' => $score,
            'rating' => Score::getRating($score),
            'sampleCount' => $n,
        ];
    }

    /** The closed pulse JSON string. Never throws. */
    public static function pulseJson(): string
    {
        $lock = self::computePanelLock();
        $shape = $lock !== null ? ['lock' => $lock] : self::pulseShape();

        return Json::encode($shape) ?? '{"score":null,"rating":null,"sampleCount":0}';
    }

    /**
     * The p'th percentile of a list of durations, byte-identical index policy to
     * Java's {@code Bubble.percentile} (index = floor(len * p), clamped) so the
     * bubble score matches across runtimes. Note this differs from
     * {@link Samples::percentile} (nearest-rank ceil) by design: the pulse mirror
     * uses Java's floor policy.
     *
     * @param list<int|float> $values
     */
    private static function percentile(array $values, float $p): int
    {
        $len = count($values);
        if ($len === 0) {
            return 0;
        }
        sort($values);
        $idx = (int) ($len * $p);
        if ($idx > $len - 1) {
            $idx = $len - 1;
        }

        return (int) $values[$idx];
    }

    // ─── Server-authority lock overlay (VAULT contract, owner UX) ────────────

    /**
     * The blocking-lock descriptor served to the injected snippet's paint():
     *   - {@code kind: "hidden"}  → silently hide the whole bubble.
     *   - {@code kind: "revoked"|"unpaid"|"paused"} → render the BLOCKING overlay
     *     with the paired title + body copy (owner-facing, no action button, no
     *     in-kit payment). Copy is kept byte-equal to the Node/RN overlay.
     *
     * Returns {@code null} when access is active/within-grace so the panel serves
     * its normal meter body. Never throws — a failure fails OPEN (null) so a bug
     * in the entitlement readout can never brick a paying app.
     *
     * PHP note: {@link KillSwitch} exposes a status STRING
     * ({@code visibleStatus()}) rather than Java's {@code GateKind} enum, so the
     * classification below reads that string; the resulting descriptor shape and
     * copy match Java's {@code computePanelLock} exactly.
     *
     * @return array<string,string>|null
     */
    public static function computePanelLock(): ?array
    {
        return Safe::run(static function (): ?array {
            $status = KillSwitch::visibleStatus();
            if ($status === KillSwitch::ACTIVE) {
                return null;
            }
            // Never checked in is NOT a lock: no overlay, so the ordinary panel
            // renders and computePanelNotice()'s "Not registered yet" is what the
            // developer reads. This branch must sit ABOVE the catch-all below,
            // which would otherwise blank the whole bubble.
            if ($status === KillSwitch::UNREGISTERED) {
                return null;
            }
            if ($status === KillSwitch::HIDDEN || $status === KillSwitch::TAMPERED) {
                return ['kind' => 'hidden', 'title' => '', 'body' => ''];
            }
            $detail = KillSwitch::message();
            if ($status === KillSwitch::REVOKED) {
                return [
                    'kind' => 'revoked',
                    'title' => 'Access revoked',
                    'body' => "This project's Boosthis access was revoked by the account owner. "
                        . 'Contact the owner if you think this is a mistake.',
                ];
            }
            if ($status === KillSwitch::UNPAID) {
                return [
                    'kind' => 'unpaid',
                    'title' => 'Payment required',
                    'body' => 'The Boosthis subscription for this account is unpaid. Ask the account '
                        . 'owner to renew it at boosthis.com to restore access.',
                ];
            }
            if ($status === KillSwitch::PAUSED) {
                return [
                    'kind' => 'paused',
                    'title' => 'Boosthis is paused',
                    'body' => ($detail !== null && $detail !== '')
                        ? $detail
                        : 'The owner has paused this app from the Boosthis dashboard. '
                            . 'Nothing was deleted — press Reconnect there to resume.',
                ];
            }

            // Any other non-active status is treated as silently inert.
            return ['kind' => 'hidden', 'title' => '', 'body' => ''];
        }, null);
    }

    // ─── Panel meter labels + expected axis sets ─────────────────────────────

    /**
     * Display order + labels for the panel's meter rows. Core axes always render
     * (as "measuring…" while absent); environment-dependent axes appear only
     * when honestly computable. Keys/labels track the snapshot's axis builder
     * (Snapshot::buildAxes + RuntimeVitals::addVitals) and stay byte-identical
     * to the web dashboard's AXIS_LABELS.
     *
     * @return array<string,string>
     */
    private static function panelAxisLabels(): array
    {
        return [
            'responsiveness' => 'Responsiveness',
            'resilience' => 'Resilience',
            'confidence' => 'Confidence',
            'budget' => 'On budget',
            'baseline' => 'Baseline',
            'network' => 'Network',
            'repeatedWork' => 'Repeated Work',
            'idle' => 'Idle efficiency',
            'latencyFloor' => 'Latency floor',
            'schedulerLatency' => 'Scheduler Latency',
            'memoryStability' => 'Memory stability',
            'heapHeadroom' => 'Heap headroom',
            'gcPressure' => 'GC pressure',
            'gcPauseTail' => 'GC pause tail',
            'gcGenerationBalance' => 'GC generation balance',
            'containerPressure' => 'Container pressure',
            'cpuEntitlement' => 'CPU entitlement',
            'cpuThrottling' => 'CPU throttling',
            'contextSwitching' => 'Context switching',
            'fdSaturation' => 'FD saturation',
            'jitEfficiency' => 'JIT efficiency',
            'codeCachePressure' => 'Code cache pressure',
            'threadHealth' => 'Thread health',
            'coldStart' => 'Cold start',
            'heapFragmentation' => 'Heap fragmentation',
            'bootCost' => 'Boot cost',
            'internedStrings' => 'Interned strings',
            'queueTime' => 'Queue time',
            'preloadCoverage' => 'Preload coverage',
            'patchLag' => 'Patch lag',
            'crashFree' => 'Crash-free',
            'reliability' => 'Reliability',
            'processCpuLoad' => 'Process CPU',
            'systemLoad' => 'Host Load',
            'residentGrowth' => 'Resident Memory',
            'fpmWorkerSaturation' => 'FPM Workers',
            'jitBufferPressure' => 'JIT Buffer',
            'includeChurn' => 'Include Churn',
            'leakWatch' => 'Leak Watch',
            'cookieExposure' => 'Cookie Exposure',
            'devPosture' => 'Dev Posture',
            'accessPressure' => 'Access Pressure',
            'refusalHonesty' => 'Refusal honesty',
            'upstreamCache' => 'Upstream Cache',
            'connectionSetup' => 'Connection Setup',
            'backpressure' => 'Backpressure',
            'timeoutHeadroom' => 'Timeout Headroom',
            'uptimeStability' => 'Uptime Stability',
            'routeFailures' => 'Route Failures',
            'unhandledErrors' => 'Unhandled Errors',
            'failureContainment' => 'Failure Containment',
            'exceptionChurn' => 'Exception Churn',
            'timerHealth' => 'Timer Health',
            'fileIoWait' => 'Slow File I/O',
            'socketWriteStall' => 'Socket Write Stalls',
            'workerRestarts' => 'Worker restarts',
            'workerImbalance' => 'Worker imbalance',
        ];
    }

    /**
     * The core axes that always render (as pending while absent). PHP, like the
     * Go/Java kits, has no budget module, so "budget" is NOT core — a core axis
     * that can never resolve would be a forever-pending promise, violating the
     * honest-N/A doctrine.
     *
     * @return list<string>
     */
    private static function panelCoreAxes(): array
    {
        return ['responsiveness', 'resilience'];
    }

    /**
     * The additive axes this kit reliably produces once it has served some
     * traffic, keyed by their WIRE axis keys. Each one missing from the live
     * snapshot is appended as a muted "warming up" row, so the in-app panel
     * shows the SAME meter set the web dashboard renders for a PHP project.
     * Environment-gated axes are deliberately ABSENT: they can be honestly N/A
     * forever, and a permanently-warming row would be a fake meter.
     *
     * @return list<string>
     */
    private static function panelWarmingAxes(): array
    {
        return [
            // memoryStability is NOT here: a PHP request lives and dies in one
            // process, so there is no heap trend to follow across a minute —
            // the axis is honestly absent forever, not warming.
            'reliability',
            'crashFree',
            // The 2026-08 batch. heapFragmentation reads the allocator's own
            // counters (present on every build) and bootCost reads the request
            // clock, so both resolve on any live PHP app — fragmentation with
            // a verdict where memory_limit gives it a ceiling to judge against,
            // and as megabytes without one. internedStrings /
            // queueTime / preloadCoverage stay OUT: no OPcache, no upstream
            // header and no preloading are honest permanent N/As.
            'heapFragmentation',
            'bootCost',
            'baseline',
            'idle',
            'latencyFloor',
            // accessPressure warms on ordinary traffic (10 min + 50 responses),
            // so it belongs in the warming set — quote the real gate below.
            'accessPressure',
            'refusalHonesty',
            // includeChurn resolves for every SAPI once the app has served its
            // warm-up requests (INCLUDE_WARMUP_REQUESTS = 20), so it is a real
            // warming row. fpmWorkerSaturation and jitBufferPressure are NOT
            // here: both are honestly N/A forever under mod_php / CLI / no-JIT,
            // and a permanently-warming row would be a fake meter.
            'includeChurn',
            // The kit reads capacity from the volume holding its state, so this
            // resolves on the first snapshot under every supported SAPI.
            'diskPressure',
            // Memory and collection. Every reading here is one this engine can take
            // and that fills with ordinary use, so the panel warms the same rows the
            // web dashboard does for the same app rather than showing fewer meters.
            'gcPressure',
            'gcTax',
            'heapHeadroom',
            'memoryPerRequest',
            'memoryStability',
        ];
    }

    /** Caption shown on an expected-but-not-yet-measurable axis row. */
    private const PANEL_WARMING_CAPTION = 'warming up';

    /**
     * WHICH silence a no-score row is: 'pending' (warming up — a score is
     * coming), 'not-scored' (a real reading deliberately never graded) or
     * 'not-available' (this host cannot take the reading at all). One decision
     * shared by every no-score row this panel builds, so the object-axis loop
     * and the warming backfill can never drift apart.
     *
     * The order is the order of trust: the axis's own label first, then
     * positive evidence beside a zero. A bare `measurable: 0` is NOT evidence —
     * axes here ship a zero while still gathering (a map left with nothing to
     * compare, a baseline whose routes were all too fast to divide by), and
     * telling those customers to stop waiting is the same lie in the other
     * direction.
     *
     * @param mixed $axis
     */
    private static function noScoreRating(string $key, $axis): string
    {
        unset($key);
        if (!is_array($axis)) {
            return 'pending';
        }
        $declared = $axis['rating'] ?? null;
        if ($declared === Score::NOT_SCORED || $declared === Score::NOT_AVAILABLE) {
            return (string) $declared;
        }
        return 'pending';
    }

    /**
     * Caption for a pending row. Where the kit has a real gate constant, the
     * caption quotes it, so the row says WHAT it is waiting for rather than
     * leaving the developer guessing.
     */
    private static function panelWarmingCaption(string $key): string
    {
        $gates = [
            'accessPressure' => 'needs 10 min + 50 responses',
            'refusalHonesty' => '10 min minimum window',
            'reliability' => 'needs 10 responses',
            'crashFree' => 'needs 5 min',
            'includeChurn' => 'needs 20 requests',
            'heapFragmentation' => 'needs 10 requests',
            'bootCost' => 'needs 10 requests',
        ];

        return isset($gates[$key])
            ? "warming up \u{00b7} {$gates[$key]}"
            : self::PANEL_WARMING_CAPTION;
    }

    /**
     * Human label for a panel axis: the fixed label when known, else a humanized
     * camelCase key (never dropped).
     */
    private static function panelLabel(string $key): string
    {
        $labels = self::panelAxisLabels();
        if (isset($labels[$key])) {
            return $labels[$key];
        }
        $words = (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $key);
        if ($words === '') {
            return $words;
        }

        return strtoupper(substr($words, 0, 1)) . substr($words, 1);
    }

    /** Whole numbers print without a fraction ("2" not "2.0"), matching JS. */
    private static function numStr(float $n): string
    {
        if ($n === floor($n) && is_finite($n)) {
            return (string) (int) $n;
        }
        $s = (string) $n;

        return $s;
    }

    /**
     * Honest numeric caption for one axis. Prefers the axis's own caption; falls
     * back to per-key formats mirroring the web panel, then to a bare score.
     * Display-only; length-capped, control-char-stripped closed channel — never
     * routes, URLs, or raw samples.
     *
     * @param array<string,mixed> $axis
     */
    private static function panelCaption(string $key, array $axis): string
    {
        return (string) Safe::run(static function () use ($key, $axis): string {
            $cap = $axis['caption'] ?? null;
            $p75 = $axis['p75Ms'] ?? null;
            $tail = $axis['tailRatio'] ?? null;
            $total = $axis['total'] ?? null;

            if (is_string($cap) && trim($cap) !== '') {
                $out = $cap;
            } elseif ($key === 'responsiveness' && is_numeric($p75)) {
                $out = round((float) $p75) . ' ms p75';
                $count = $axis['count'] ?? null;
                if (is_numeric($count)) {
                    $out .= " \u{00b7} " . (int) $count . ' reqs';
                }
            } elseif ($key === 'resilience') {
                $nn = $axis['sampleCount'] ?? null;
                $suffix = is_numeric($nn) ? " \u{00b7} " . (int) $nn . ' samples' : '';
                if (is_numeric($tail)) {
                    $out = self::numStr((float) $tail) . "\u{00d7} tail" . $suffix;
                } elseif (is_numeric($axis['p99Ms'] ?? null)
                    && (float) $axis['p99Ms'] <= Thresholds::RESILIENCE_TAIL_FLOOR_MS) {
                    $out = 'every request under ' . Thresholds::RESILIENCE_TAIL_FLOOR_MS
                        . 'ms' . $suffix;
                } elseif (is_numeric($axis['p50Ms'] ?? null)
                    && (float) $axis['p50Ms'] < Thresholds::RESILIENCE_MIN_MEDIAN_MS) {
                    $out = 'tail not judged' . " \u{00b7} " . 'median under '
                        . Thresholds::RESILIENCE_MIN_MEDIAN_MS . 'ms' . $suffix;
                } else {
                    $out = 'warming up' . $suffix;
                }
            } elseif ($key === 'budget' && is_numeric($total)) {
                $onBudget = $axis['onBudget'] ?? 0;
                $ob = is_numeric($onBudget) ? (int) $onBudget : 0;
                $out = $ob . '/' . (int) $total . ' on budget';
            } elseif ($key === 'baseline') {
                $wr = $axis['worstRatio'] ?? 0;
                $ratio = is_numeric($wr) ? (float) $wr : 0.0;
                $anomalies = $axis['anomalyCount'] ?? 0;
                $an = is_numeric($anomalies) ? (int) $anomalies : 0;
                // A route whose earlier window is all sub-millisecond has no
                // denominator to divide by, so it was NOT examined: an
                // abstention must never read as steadiness.
                $unscored = $axis['unscoredRoutes'] ?? 0;
                $skipped = is_numeric($unscored) ? (int) $unscored : 0;
                $scoredRaw = $axis['scoredRoutes'] ?? null;
                $scored = is_numeric($scoredRaw) ? (int) $scoredRaw : null;
                if ($scored === 0) {
                    $out = "not judged \u{00b7} " . $skipped . ' route'
                        . ($skipped === 1 ? '' : 's') . ' too fast to compare';
                } else {
                    $out = $an > 0
                        ? self::numStr($ratio) . "\u{00d7} worst \u{00b7} " . $an . ' regressed'
                        : 'steady vs baseline';
                    if ($skipped > 0) {
                        $out .= " \u{00b7} " . $skipped . ' too fast to compare';
                    }
                }
            } elseif ($key === 'network') {
                $p75n = $axis['p75Ms'] ?? 0;
                $pp = is_numeric($p75n) ? (int) round((float) $p75n) : 0;
                $stallPct = $axis['stallPct'] ?? 0;
                $sp = is_numeric($stallPct) ? (float) $stallPct : 0.0;
                $out = $pp . " ms p75 \u{00b7} " . self::numStr($sp) . '% stalled';
            } elseif ($key === 'repeatedWork') {
                $worst = is_numeric($axis['worstRepeats'] ?? null)
                    ? (int) $axis['worstRepeats'] : 1;
                $hits = is_numeric($axis['requestsWithRepeat'] ?? null)
                    ? (int) $axis['requestsWithRepeat'] : 0;
                $seen = is_numeric($axis['watchedRequests'] ?? null)
                    ? (int) $axis['watchedRequests'] : 0;
                $pct = is_numeric($axis['repeatTimePct'] ?? null)
                    ? (float) $axis['repeatTimePct'] : 0.0;
                $out = $hits > 0
                    ? 'worst ' . $worst . "\u{00d7} same call \u{00b7} "
                        . $hits . '/' . $seen . " requests \u{00b7} "
                        . self::numStr($pct) . '% of time'
                    : 'no repeats \u{00b7} ' . $seen . ' request'
                        . ($seen === 1 ? '' : 's') . ' watched';
            } elseif ($key === 'idle') {
                $bp = $axis['idleBusyPct'] ?? 0;
                $busy = is_numeric($bp) ? (float) $bp : 0.0;
                $out = self::numStr($busy) . '% busy while idle';
            } elseif ($key === 'latencyFloor') {
                $wm = $axis['worstMs'] ?? 0;
                $worst = is_numeric($wm) ? (int) $wm : 0;
                $wc = $axis['windowCount'] ?? 0;
                $win = is_numeric($wc) ? (int) $wc : 0;
                $out = $worst . " ms worst window \u{00b7} " . $win . ' windows';
            } elseif ($key === 'schedulerLatency') {
                $p99 = $axis['p99Ms'] ?? 0;
                $p99v = is_numeric($p99) ? (float) $p99 : 0.0;
                $out = self::numStr($p99v) . ' ms p99 scheduler wait';
            } elseif ($key === 'patchLag') {
                // Build age ONLY — never implies the app is safe/patched.
                $ageObj = $axis['buildAgeMs'] ?? 0;
                $ageMs = is_numeric($ageObj) ? (float) $ageObj : 0.0;
                $ageDays = (int) round($ageMs / 86400000.0);
                $out = 'build ~' . $ageDays . 'd old';
            } else {
                $out = (string) ($axis['score'] ?? '') . '/100';
            }

            // Host-suspend honesty note.
            $sd = $axis['suspendDiscounts'] ?? null;
            if (is_numeric($sd) && (int) $sd > 0) {
                $out .= " \u{00b7} host-suspend gaps discounted (" . (int) $sd . ')';
            }

            return self::clampCaption($out);
        }, '');
    }

    /** Control-char-strip + length-cap a caption for the closed channel. */
    private static function clampCaption(string $s): string
    {
        $out = '';
        $len = mb_strlen($s, 'UTF-8');
        for ($i = 0; $i < $len && mb_strlen($out, 'UTF-8') < self::PANEL_CAPTION_MAX; $i++) {
            $c = mb_substr($s, $i, 1, 'UTF-8');
            if ($c >= ' ') {
                $out .= $c;
            }
        }

        return $out;
    }

    /**
     * The Confidence panel row built from the 4-scalar confidence axis. Returns
     * the closed {@code {key,label,score,rating,caption}} row, or {@code null}
     * when the confidence scalars are absent. Like RN, confidence has no 0-100
     * score, so {@code score} is null and the tile reads off its rating +
     * caption. Never throws.
     *
     * @param array<string,mixed> $axes
     * @return array<string,mixed>|null
     */
    private static function confidencePanelRow(array $axes): ?array
    {
        $level = $axes['confidence'] ?? null;
        if (!is_string($level)) {
            return null;
        }
        $rating = $axes['confidenceRating'] ?? null;
        $rating = is_string($rating) ? $rating : 'pending';
        if ($rating !== Score::GOOD && $rating !== Score::NEEDS_WORK && $rating !== Score::POOR) {
            $rating = 'pending';
        }
        $caption = $axes['confidenceCaption'] ?? null;
        $caption = is_string($caption) ? $caption : '';
        $mounts = $axes['confidenceMounts'] ?? 0;
        $mounts = is_numeric($mounts) ? (int) $mounts : 0;
        if ($caption === '') {
            $caption = $mounts . ' samples';
        }

        return [
            'key' => 'confidence',
            'label' => self::panelLabel('confidence'),
            'score' => null,
            'rating' => $rating,
            'caption' => self::clampCaption($caption),
        ];
    }

    /**
     * The panel's notice kind, or {@code null} when there is nothing to say.
     * Ordered by how completely each state blocks the developer:
     *
     *   install-id-not-uuid  the server rejected the id outright
     *   not-registered       the kit never registered (no key, or no reach)
     *   sharing-off          registered, but nothing is being uploaded
     *
     * The last one is the quiet failure this notice exists to end. An install
     * with sharing off still runs, still renders live local meters, and still
     * looks completely healthy — while the dashboard it is supposed to feed
     * stays empty. Nothing anywhere told the developer that, so the panel does.
     *
     * Fail-open by construction: any error renders the panel with no notice
     * rather than no panel.
     */
    public static function computePanelNotice(): ?string
    {
        return Safe::run(static function (): ?string {
            self::askRegistration();
            if (Telemetry::invalidInstallId()) {
                return 'install-id-not-uuid';
            }
            $verdict = Registration::verdict();
            if ($verdict === 'unregistered') {
                return 'not-registered';
            }
            if ($verdict === 'unknown' && !Telemetry::isRegistered()) {
                return 'registration-unknown';
            }
            if (!Telemetry::fullTelemetry()) {
                return 'sharing-off';
            }
            if (Telemetry::isLastUploadAttemptFailed() && Telemetry::lastUploadFailure() !== null) {
                return 'uploads-failing';
            }

            return null;
        }, null);
    }

    private static function askRegistration(): void
    {
        try {
            if (Telemetry::isRegistered()) {
                Registration::markRegistrationConfirmed();
            }
            Registration::checkOnce(
                RuntimeFlags::apiBase(),
                Telemetry::installId(),
                Telemetry::projectKey()
            );
        } catch (\Throwable $e) {
            Registration::markRegistrationUnreachable();
        }
    }

    /**
     * The panel's dropped-rows line, or {@code null} when the server has never
     * reported a dropped row for this install. Carries only a count and
     * CODE-DEFINED cause markers — the words shown in the panel are literals in
     * the injected snippet, exactly like the notice banner, so no server text
     * is ever rendered. Fail-open: any error degrades to null (no line), never
     * a blank panel.
     *
     * @return array{count:int,causes:list<string>}|null
     */
    public static function computePanelDrops(): ?array
    {
        return Safe::run(static function (): ?array {
            $count = Telemetry::droppedRowCount();
            if ($count <= 0) {
                return null;
            }

            return ['count' => $count, 'causes' => Telemetry::droppedRowCauses()];
        }, null);
    }

    /**
     * The closed panel shape: the pulse trio plus an ordered list of meter rows,
     * each projected onto the fixed {@code {key,label,score,rating,caption}}
     * allowlist. Core axes render as pending/measuring… while absent; expected
     * additive axes render as pending/warming up until they have honest data
     * (same meter set as the web dashboard); environment-dependent axes appear
     * only when honestly computable. Never includes route labels, URLs, or raw
     * samples. Fail-open: any error degrades to the pulse-only shape.
     *
     * @return array<string,mixed>
     */
    public static function panelShape(): array
    {
        $out = self::pulseShape();
        self::askRegistration();
        // Already rendered here so the browser cannot invent a different
        // unnamed/not-yet-received fallback from the status page.
        $out['project'] = ProjectIdentity::displayCurrent();
        $installId = Telemetry::installId();
        $out['installId'] = is_string($installId) && $installId !== '' ? $installId : null;

        $axes = Safe::run(static function (): array {
            $samples = Samples::all();

            return Snapshot::buildAxes($samples);
        }, []);
        if (!is_array($axes)) {
            $axes = [];
        }

        $rows = [];
        $core = self::panelCoreAxes();

        Safe::fire(static function () use (&$rows, $axes, $core, $out): void {
            $ordered = [];
            foreach ($core as $c) {
                $ordered[] = $c;
            }
            foreach (array_keys($axes) as $key) {
                if (!in_array($key, $ordered, true)) {
                    $ordered[] = $key;
                }
            }
            foreach ($ordered as $key) {
                $raw = $axes[$key] ?? null;
                $isCore = in_array($key, $core, true);
                $axis = is_array($raw) ? $raw : null;
                $scoreVal = $axis !== null ? ($axis['score'] ?? null) : null;
                if ($axis === null || !is_numeric($scoreVal)) {
                    if ($key === 'resilience') {
                        $reading = $axis ?? ['sampleCount' => $out['sampleCount'] ?? 0];
                        $rows[] = [
                            'key' => $key,
                            'label' => self::panelLabel($key),
                            'score' => null,
                            'rating' => self::noScoreRating($key, $axis),
                            'caption' => self::panelCaption($key, $reading),
                        ];
                        continue;
                    }
                    if ($isCore) {
                        $rows[] = [
                            'key' => $key,
                            'label' => self::panelLabel($key),
                            'score' => null,
                            // WHICH silence this is, not just "no score yet": a
                            // reading that declared itself never-graded, or
                            // unreadable on this host, said so on the axis, and
                            // hardcoding 'pending' threw that word away.
                            'rating' => self::noScoreRating($key, $axis),
                            'caption' => "measuring\u{2026}",
                        ];
                    }
                    continue;
                }
                $score = (int) round((float) $scoreVal);
                $score = max(0, min(100, $score));
                $ratingVal = $axis['rating'] ?? null;
                $rating = is_string($ratingVal) ? $ratingVal : null;
                if ($rating !== Score::GOOD && $rating !== Score::NEEDS_WORK
                    && $rating !== Score::POOR) {
                    $rating = Score::getRating($score);
                }
                $safeKey = strlen($key) > 40 ? substr($key, 0, 40) : $key;
                $rows[] = [
                    'key' => $safeKey,
                    'label' => self::panelLabel($key),
                    'score' => $score,
                    'rating' => $rating,
                    'caption' => self::panelCaption($key, $axis),
                ];
            }
        });

        // Confidence renders as its OWN tile (a 4-scalar axis, not an object with
        // a score), so build it explicitly. Guarded — a throw never blanks it.
        Safe::fire(static function () use (&$rows, $axes): void {
            $confRow = self::confidencePanelRow($axes);
            if ($confRow !== null) {
                $rows[] = $confRow;
            }
        });

        // Warming-up rows: every expected additive axis with no honest numeric
        // score yet still renders, muted, so a fresh app shows the same meter
        // set here as on the web dashboard.
        Safe::fire(static function () use (&$rows, $axes): void {
            $rendered = [];
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $rendered[(string) ($row['key'] ?? '')] = true;
                }
            }
            foreach (self::panelWarmingAxes() as $key) {
                if (isset($rendered[$key])) {
                    continue;
                }
                // An axis can be PRESENT and honest with no score at all: the
                // host cannot measure it, or the axis deliberately refuses to
                // grade what it saw (refusalHonesty reports an observation,
                // never a verdict). Those arrive with their own caption, and
                // "warming up" over real data would be exactly the lie this
                // panel exists to avoid.
                $caption = self::panelWarmingCaption($key);
                $raw = is_array($axes) ? ($axes[$key] ?? null) : null;
                if (is_array($raw) && is_string($raw['caption'] ?? null)
                    && trim((string) $raw['caption']) !== '') {
                    $caption = self::panelCaption($key, $raw);
                }
                // BASELINE abstaining is the same kind of reading, arriving with
                // no caption of its own: PRESENT, honest, and no score is ever
                // coming — every route's earlier window was too fast to divide
                // by. "warming up" here would be the steadiness claim this axis
                // is not entitled to make, one voice quieter.
                $baselineAbstains = $key === 'baseline' && is_array($raw)
                    && isset($raw['measurable']) && (int) $raw['measurable'] === 0;
                if ($baselineAbstains) {
                    $caption = self::panelCaption($key, $raw);
                }
                // …and the ROW says WHICH silence it is, not only the caption.
                // The caption already told the truth; the RATING said 'pending'
                // for all three, so anything that sorts, colours or counts
                // these rows still filed a permanent observation under "still
                // measuring". One shared decision with the object-axis loop
                // above, so the two can never give one reading two different
                // words.
                $rating = self::noScoreRating($key, $raw);
                $rows[] = [
                    'key' => $key,
                    'label' => self::panelLabel($key),
                    'score' => null,
                    'rating' => $rating,
                    'caption' => $caption,
                ];
            }
        });

        $out['axes'] = $rows;

        // WHERE SCHEDULED JOBS LIVE, as a CLOSED token — always present. This
        // kit exports no job-reporting call (background work reaches Boosthis
        // only as the aggregate reading), so the page STATES that rather than
        // leaving a silence a reader takes for "this app has no jobs". The
        // page holds every word; see
        // docs/decisions/kit-page-says-where-jobs-live.md.
        $out['jobs'] = ['reporting' => self::PANEL_JOBS_REPORTING];

        $projectKey = Telemetry::projectKeyDetails();
        $out['projectKey'] = [
            'display' => $projectKey['display'],
            'source' => ProjectKey::describe($projectKey),
        ];

        // A REJECTED registration must never read as connected/normal/awaiting.
        // The key is OMITTED entirely when registration was not refused.
        $notice = self::computePanelNotice();
        if ($notice !== null) {
            $out['notice'] = ['kind' => $notice];
            if ($notice === 'uploads-failing') {
                $failure = Telemetry::lastUploadFailure();
                $out['notice']['reason'] = is_array($failure) ? ($failure['reason'] ?? null) : null;
                $out['notice']['lost'] = Telemetry::droppedUploadCount();
            }
            if (Telemetry::inactiveNotice() !== null) {
                $out['notice']['body'] = Telemetry::inactiveNotice();
            }
        }

        // Rows the server threw away. Additive: it never replaces the notice
        // above, because "nothing is being uploaded" and "some of what was
        // uploaded was refused" are different problems and a developer may have
        // both. Omitted entirely when nothing has been dropped, so a healthy
        // app shows nothing at all.
        $drops = self::computePanelDrops();
        if ($drops !== null) {
            $out['drops'] = $drops;
        }

        return $out;
    }

    /** The closed panel JSON string (lock-only when locked). Never throws. */
    public static function panelJson(): string
    {
        $lock = self::computePanelLock();
        $shape = $lock !== null ? ['lock' => $lock] : self::panelShape();

        return Json::encode($shape) ?? '{"score":null,"rating":null,"sampleCount":0}';
    }

    /**
     * The account auth-context shape:
     * {@code {endpoint, installId, deleteToken, fullTelemetry}} resolved from the
     * persisted registration (the SAME source of truth the live-read + snapshot
     * paths use). The first three are null when no registration exists.
     *
     * PHP note: the field names below ({@code deleteToken}, {@code installId},
     * {@code fullTelemetry}) are the SERVER's wire-contract names — the same the
     * Java/Go/Python bubbles emit — not kit-invented meter fields, and they are
     * emitted ONLY through the loopback-gated account read. They intentionally
     * mirror Java's {@code accountJson} for account-card parity.
     *
     * @return array<string,mixed>
     */
    public static function accountShape(): array
    {
        $out = [
            'endpoint' => null,
            'installId' => null,
            'deleteToken' => null,
            'fullTelemetry' => false,
        ];
        Safe::fire(static function () use (&$out): void {
            $out['endpoint'] = RuntimeFlags::apiBase();
            $id = Telemetry::installId();
            if (is_string($id) && $id !== '') {
                $out['installId'] = $id;
            }
            $dt = Telemetry::deleteToken();
            if (is_string($dt) && $dt !== '') {
                $out['deleteToken'] = $dt;
            }
            $out['fullTelemetry'] = Telemetry::fullTelemetry();
        });

        return $out;
    }

    // ─── Path derivation (parity helpers) ────────────────────────────────────

    /**
     * The panel path derived from the pulse path by suffix swap, failing open to
     * the pulse path if it does not end in {@code /pulse}. Mirrors the sibling
     * kits so the derivation policy is shared.
     */
    public static function panelPathFromPulse(string $pulsePath): string
    {
        $suffix = '/pulse';
        if (str_ends_with($pulsePath, $suffix)) {
            return substr($pulsePath, 0, strlen($pulsePath) - strlen($suffix)) . '/panel';
        }

        return $pulsePath;
    }

    /**
     * The account path derived from the panel path by suffix swap
     * ({@code /panel} -> {@code /account}), failing open to an empty string when
     * the panel path does not end in {@code /panel}.
     */
    public static function accountPathFromPanel(string $panelPath): string
    {
        $suffix = '/panel';
        if (str_ends_with($panelPath, $suffix)) {
            return substr($panelPath, 0, strlen($panelPath) - strlen($suffix)) . '/account';
        }

        return '';
    }

    // ─── HTML injection ──────────────────────────────────────────────────────

    /**
     * The full self-contained bubble markup: styles + an inline {@code <script>}
     * IIFE (closed shadow DOM, no globals, textContent-only writes, every step
     * try/catch-guarded). The badge shows the live pulse; tapping it opens the
     * dashboard-first panel with meter rows.
     *
     * The panel data is INLINED into the snippet (as {@code {{PANEL_DATA}}}) so
     * the panel renders without any served endpoint — a page-only PHP kit has no
     * HTTP handler for {@code /_boosthis/panel}. The snippet still re-reads that
     * inlined shape on its poll interval (a fresh {@link html()} on the next page
     * load carries fresh numbers). Everything interpolated into the script is
     * JSON-encoded through {@link scriptJson()}, which escapes {@code </} and
     * line separators so an unescaped {@code </script>} or JSON can never break
     * the host's page.
     */
    public static function html(): string
    {
        return (string) Safe::run(static function (): string {
            $panel = self::panelPathFromPulse(self::PULSE_PATH);
            $account = self::accountPathFromPanel($panel);

            // The panel read is inlined so the browser paints without a network
            // hop. computePanelLock() is respected so a locked verdict inlines
            // ONLY the lock descriptor, never the meters (nothing readable behind
            // the overlay). accountShape omits the delete token from the inlined
            // read: the loopback-only credential must never ride in page HTML, so
            // the inlined account context carries endpoint/installId/fullTelemetry
            // only and the card renders the loopback note for sign-in.
            $lock = self::computePanelLock();
            $panelData = $lock !== null ? ['lock' => $lock] : self::panelShape();

            return str_replace(
                [
                    '{{PANEL}}',
                    '{{ACCOUNT}}',
                    '{{TERMS_VERSION}}',
                    '{{PANEL_DATA}}',
                    '{{INSTALL_ID_PREFIX}}',
                    '{{INSTALL_ID_UNKNOWN_TEXT}}',
                ],
                [
                    self::scriptJson($panel),
                    self::scriptJson($account),
                    self::scriptJson(self::TERMS_VERSION_LITERAL),
                    self::scriptJson($panelData),
                    self::scriptJson(ProjectIdentity::INSTALL_ID_LABEL . ': '),
                    self::scriptJson(ProjectIdentity::INSTALL_ID_UNKNOWN_TEXT),
                ],
                self::bubbleTemplate()
            );
        }, '');
    }

    /**
     * JSON-encode a value for safe embedding inside an inline {@code <script>}.
     * On top of the kit's JSON contract this escapes the three sequences a
     * browser HTML parser would otherwise mis-handle inside a script element:
     *   - {@code </}     → {@code <\/}  (so a value can never close the tag);
     *   - U+2028 / U+2029 → escaped (line separators break JS string literals).
     * Read {@code .agents/memory/inline-script-template-escapes.md}: a single
     * stray {@code </script>} kills the whole injected script silently.
     */
    private static function scriptJson(mixed $value): string
    {
        $json = Json::encode($value);
        if ($json === null) {
            return 'null';
        }
        // Break "</" so "</script>" can never appear literally in the source.
        $json = str_replace('</', '<\/', $json);
        // Line/paragraph separators are valid JSON but break a JS string literal.
        $json = str_replace(["\u{2028}", "\u{2029}"], ['\u2028', '\u2029'], $json);

        return $json;
    }

    /**
     * Echo the bubble immediately before the closing {@code </body>} from the
     * host's shutdown path — but ONLY when it is safe. Fail-open in every branch:
     * any doubt serves the page untouched. Never throws, never emits stray bytes.
     *
     * Gating (all must hold):
     *   - {@code BOOSTHIS_DISABLED} off and {@link bubbleEnabled()} true;
     *   - the kill-switch is not silently "hidden";
     *   - the outgoing response looks like an HTML document (its Content-Type
     *     header is text/html, checked via {@code headers_list()});
     *   - the status is not a redirect (3xx) and not otherwise non-2xx;
     *   - a Content-Encoding is not set (a compressed body cannot be spliced);
     *   - the measured request is not Boosthis measuring its OWN dashboard
     *     (the caller passes {@code $selfMeasured = true}, the PHP equivalent of
     *     the other kits' {@code bubble: false} on the dogfood middleware).
     *
     * @param bool|null $option       explicit visibility option (precedence 3).
     * @param bool      $selfMeasured true when this response is Boosthis's own
     *                                dashboard — never inject over ourselves.
     */
    public static function inject(?bool $option = null, bool $selfMeasured = false): void
    {
        Safe::fire(static function () use ($option, $selfMeasured): void {
            if ($selfMeasured) {
                return;
            }
            if (!self::bubbleEnabled($option)) {
                return;
            }
            if (!self::responseLooksInjectable()) {
                return;
            }
            $markup = self::html();
            if ($markup === '') {
                return;
            }
            // The host may have already flushed its buffers; only append when we
            // can still write to output without corrupting an in-flight body.
            // A response that is already fully sent (headers_sent with no active
            // output buffer to piggy-back on) is left alone.
            echo $markup;
        });
    }

    /**
     * True when the current response looks like a splice-safe HTML page. Reads
     * the emitted headers via {@code headers_list()} and the response code via
     * {@code http_response_code()}. Conservative — any doubt returns false so the
     * host page is served untouched.
     */
    private static function responseLooksInjectable(): bool
    {
        return (bool) Safe::run(static function (): bool {
            // Status must be a normal 2xx page. Redirects (3xx), 4xx and 5xx are
            // skipped — a redirect has no body to carry a bubble, and an error
            // page must not be reshaped by us.
            $code = self::currentStatusCode();
            if ($code < 200 || $code >= 300) {
                return false;
            }

            $contentType = null;
            $hasEncoding = false;
            $isAttachment = false;
            if (function_exists('headers_list')) {
                foreach (headers_list() as $header) {
                    $lower = strtolower($header);
                    if (str_starts_with($lower, 'content-type:')) {
                        $contentType = trim(substr($header, strlen('content-type:')));
                    } elseif (str_starts_with($lower, 'content-encoding:')) {
                        $val = trim(substr($header, strlen('content-encoding:')));
                        if ($val !== '') {
                            $hasEncoding = true;
                        }
                    } elseif (str_starts_with($lower, 'content-disposition:')) {
                        if (str_contains($lower, 'attachment')) {
                            $isAttachment = true;
                        }
                    }
                }
            }

            // A compressed body cannot be spliced; a download must not be touched.
            if ($hasEncoding || $isAttachment) {
                return false;
            }

            // Must be HTML. NO Content-Type at all is NOT treated as HTML here:
            // unlike Java (which buffers the body and can sniff it), inject()
            // runs at echo time with no buffered body to inspect, so we only act
            // on an explicit text/html declaration — fail-closed for the page.
            if ($contentType === null) {
                return false;
            }
            $ctLower = strtolower($contentType);
            if (!str_contains($ctLower, 'text/html')) {
                return false;
            }

            return true;
        }, false);
    }

    /** Best-effort current HTTP status code; defaults to 200 when unknown. */
    private static function currentStatusCode(): int
    {
        if (function_exists('http_response_code')) {
            $code = http_response_code();
            if (is_int($code) && $code > 0) {
                return $code;
            }
        }

        return 200;
    }

    /**
     * The bubble template. Stored as a NOWDOC so every byte — including the
     * {@code \\uXXXX} sequences the browser decodes and the {@code $} in the
     * account endpoint regex — reaches the page verbatim, with NO PHP
     * interpolation. This is the one place a stray escape would silently kill
     * the whole injected script (see inline-script-template-escapes.md), and a
     * nowdoc removes that whole class of bug: PHP touches nothing between the
     * markers. The four {@code {{...}}} placeholders are substituted afterwards
     * with values already run through {@link scriptJson()}.
     *
     * The script mirrors the Java {@code BUBBLE_TEMPLATE} structure and copy end
     * to end (RN DARK_THEME palette, hero + Score breakdown + Runtime health +
     * legend + Connect card + Terms gate + Terms/Privacy footer). The only PHP
     * difference: the panel read is INLINED as {@code PANEL_DATA} and the panel
     * poll returns that inlined shape, since a page-only kit serves no endpoint.
     */
    private static function bubbleTemplate(): string
    {
        return <<<'BUBBLE'

<script>(function(){try{
if(window.__boosthisBubble)return;window.__boosthisBubble=1;
var TERMS_VERSION={{TERMS_VERSION}};
var TERMS_KEY="boosthis:terms-accepted:v1";
var ACCEPTED_MEM=false;
var LINKED=false;
var PANEL_DATA={{PANEL_DATA}};
function termsRead(){try{
var raw=window.localStorage&&window.localStorage.getItem(TERMS_KEY);
if(!raw)return null;var p=JSON.parse(raw);
if(p&&typeof p.version==="string")return p;return null;
}catch(e){return null;}}
function termsAccepted(){try{
if(ACCEPTED_MEM)return true;
var p=termsRead();if(!p||p.version!==TERMS_VERSION)return false;
var iid=(typeof ACTX!=="undefined"&&ACTX&&typeof ACTX.installId==="string")?ACTX.installId:null;
if(iid===null)return true;
return (p.installId||null)===iid;
}catch(e){return ACCEPTED_MEM;}}
function termsWrite(){try{ACCEPTED_MEM=true;
var iid=(typeof ACTX!=="undefined"&&ACTX&&typeof ACTX.installId==="string")?ACTX.installId:null;
if(window.localStorage)window.localStorage.setItem(TERMS_KEY,
JSON.stringify({version:TERMS_VERSION,at:Date.now(),installId:iid}));
}catch(e){}}
function termsClear(){try{ACCEPTED_MEM=false;
if(window.localStorage)window.localStorage.removeItem(TERMS_KEY);
}catch(e){}}
var host=document.createElement("div");
host.style.cssText="position:fixed;bottom:18px;right:18px;z-index:2147483000;";
var root=host.attachShadow?host.attachShadow({mode:"closed"}):host;
var T={bg:"#0b0c10",card:"#15171c",br:"#262932",fg:"#e6e7eb",mut:"#8b8f99",pri:"#f97316"};
var COLORS={good:"#4ade80","needs-work":"#fbbf24",poor:"#f87171"};
var RL={good:"GOOD","needs-work":"NEEDS WORK",poor:"POOR"};
var btn=document.createElement("button");
btn.type="button";btn.setAttribute("aria-label","Boosthis performance bubble");
btn.style.cssText="width:48px;height:48px;border-radius:50%;background:"+T.bg+";border:2.5px solid "+T.mut+";color:"+T.pri+";font-size:22px;line-height:1;cursor:pointer;box-shadow:0 4px 14px rgba(0,0,0,.4);display:flex;align-items:center;justify-content:center;padding:0;font-family:system-ui,sans-serif;";
var bTxt0=document.createElement("span");bTxt0.textContent="\u26a1";bTxt0.style.cssText="pointer-events:none;";btn.appendChild(bTxt0);
var panel=document.createElement("div");
panel.setAttribute("role","dialog");panel.setAttribute("aria-label","Boosthis dashboard");
panel.style.cssText="display:none;position:absolute;bottom:56px;right:0;width:300px;max-width:calc(100vw - 36px);max-height:72vh;overflow:auto;overscroll-behavior:contain;-webkit-overflow-scrolling:touch;scrollbar-width:thin;scrollbar-color:"+T.mut+" transparent;background:"+T.bg+";background:linear-gradient("+T.bg+" 50%,rgba(0,0,0,0)) top/100% 20px no-repeat local,linear-gradient(rgba(0,0,0,0),"+T.bg+" 50%) bottom/100% 20px no-repeat local,radial-gradient(farthest-side at 50% 0,rgba(0,0,0,.6),rgba(0,0,0,0)) top/100% 10px no-repeat scroll,radial-gradient(farthest-side at 50% 100%,rgba(0,0,0,.6),rgba(0,0,0,0)) bottom/100% 10px no-repeat scroll,"+T.bg+";color:"+T.fg+";border:1px solid "+T.br+";border-radius:20px;padding:12px;font:12px/1.5 system-ui,sans-serif;box-shadow:0 10px 28px rgba(0,0,0,.5);text-align:left;";
var lockEl=document.createElement("div");
lockEl.setAttribute("role","alertdialog");
lockEl.setAttribute("aria-label","Boosthis locked");
lockEl.style.cssText="display:none;position:absolute;top:0;left:0;right:0;bottom:0;z-index:10;box-sizing:border-box;border-radius:20px;padding:32px 22px;flex-direction:column;align-items:center;justify-content:center;text-align:center;background:rgba(11,12,16,.92);-webkit-backdrop-filter:blur(8px);backdrop-filter:blur(8px);";
var lockTitle=document.createElement("div");
lockTitle.style.cssText="font-size:17px;font-weight:700;color:"+T.fg+";margin-bottom:8px;";
var lockBody=document.createElement("div");
lockBody.style.cssText="font-size:13px;line-height:1.5;color:"+T.mut+";max-width:240px;";
lockEl.appendChild(lockTitle);lockEl.appendChild(lockBody);
function showLock(lock){try{
lockTitle.textContent=String(lock&&lock.title||"Boosthis is locked");
lockBody.textContent=String(lock&&lock.body||"");
lockEl.style.display="flex";
panel.style.overflow="hidden";
}catch(e){}}
function hideLock(){try{
lockEl.style.display="none";panel.style.overflow="auto";
}catch(e){}}
function card(){
var c=document.createElement("div");
c.style.cssText="background:"+T.card+";border:1px solid "+T.br+";border-radius:16px;padding:12px 14px;margin-bottom:10px;";
return c;}
function cardTitle(tx){
var d=document.createElement("div");
d.style.cssText="font-size:13px;font-weight:700;color:"+T.fg+";";d.textContent=tx;return d;}
function cardSub(tx){
var d=document.createElement("div");
d.style.cssText="font-size:10.5px;color:"+T.mut+";margin-top:1px;margin-bottom:2px;";d.textContent=tx;return d;}
var hero=card();
var hName=document.createElement("div");
hName.style.cssText="font-size:16px;font-weight:800;color:"+T.fg+";";hName.textContent="Boosthis";
var hSub=document.createElement("div");
hSub.style.cssText="font-size:11px;color:"+T.mut+";";hSub.textContent="PHP Performance";
var hProj=document.createElement("div");
hProj.style.cssText="font-size:11px;color:"+T.mut+";margin-top:3px;word-break:break-word;";
hProj.textContent="Project: Not received from Boosthis yet";
var hInstall=document.createElement("div");
hInstall.style.cssText="font-size:11px;color:"+T.mut+";margin-top:2px;word-break:break-all;";
hInstall.textContent={{INSTALL_ID_PREFIX}}+{{INSTALL_ID_UNKNOWN_TEXT}};
var gw=document.createElement("div");gw.style.cssText="text-align:center;padding:10px 0 2px;";
var num=document.createElement("div");
num.style.cssText="font-size:46px;font-weight:800;line-height:1;letter-spacing:-1px;color:"+T.fg+";";
num.textContent="\u2013";
var of=document.createElement("div");
of.style.cssText="font-size:11px;color:"+T.mut+";margin-top:2px;";of.textContent="/ 100";
var badge=document.createElement("div");
badge.style.cssText="display:inline-flex;align-items:center;gap:5px;margin-top:8px;padding:3px 12px;border-radius:999px;font-size:10px;font-weight:700;letter-spacing:.6px;background:"+T.br+";color:"+T.mut+";border:1px solid "+T.br+";";
var bDot=document.createElement("span");
bDot.style.cssText="width:6px;height:6px;border-radius:50%;background:"+T.mut+";";
var bTxt=document.createElement("span");bTxt.textContent="MEASURING";
badge.appendChild(bDot);badge.appendChild(bTxt);
var stat=document.createElement("div");
stat.style.cssText="text-align:center;margin-top:7px;font-size:10.5px;color:"+T.mut+";";
stat.textContent="measuring \u2014 handle a few requests";
gw.appendChild(num);gw.appendChild(of);
hero.appendChild(hName);hero.appendChild(hSub);hero.appendChild(hProj);hero.appendChild(hInstall);hero.appendChild(gw);
var bWrap=document.createElement("div");bWrap.style.cssText="text-align:center;";bWrap.appendChild(badge);
var hCap=document.createElement("div");
hCap.style.cssText="text-align:center;margin-top:5px;font-size:10px;line-height:1.4;color:"+T.mut+";";
hCap.textContent="Measured on this device. Not proof anything reached Boosthis.";
hero.appendChild(bWrap);hero.appendChild(stat);hero.appendChild(hCap);
var bd=card();
bd.appendChild(cardTitle("Score breakdown"));
bd.appendChild(cardSub("this process \u00b7 lower is better"));
var bdRows=document.createElement("div");bd.appendChild(bdRows);
var formula=document.createElement("div");
formula.style.cssText="font-size:10px;color:"+T.mut+";margin-top:8px;";
formula.textContent="score = p95 vs TTI budget \u00b7 good\u226585 \u00b7 needs-work\u226560";
bd.appendChild(formula);
var hl=card();
hl.appendChild(cardTitle("Runtime health"));
hl.appendChild(cardSub("environment meters \u00b7 shown when measurable"));
var hlRows=document.createElement("div");hl.appendChild(hlRows);
var lg=card();
lg.style.cssText+="display:flex;flex-wrap:wrap;gap:6px 12px;font-size:10.5px;color:"+T.mut+";";
function lgItem(color,tx){
var s=document.createElement("span");s.style.cssText="display:inline-flex;align-items:center;gap:5px;";
var d=document.createElement("span");d.style.cssText="width:7px;height:7px;border-radius:50%;background:"+color+";";
var t=document.createElement("span");t.textContent=tx;
s.appendChild(d);s.appendChild(t);return s;}
lg.appendChild(lgItem(COLORS.good,"Good \u226585"));
lg.appendChild(lgItem(COLORS["needs-work"],"Needs work \u226560"));
lg.appendChild(lgItem(COLORS.poor,"Poor <60"));
var acct=card();
var acctBody=document.createElement("div");acct.appendChild(acctBody);
var ACTX={endpoint:null,installId:null,deleteToken:null,fullTelemetry:false,available:false};
var SESS=null;
var TELE_ON=false;var TELE_KNOWN=false;var TELE_BUSY=false;
var CLAIM_MSG=null;var CLAIM_MSG_OK=false;
var ACCT_LINK_PROOF_MSG="Couldn\u2019t link this project. Linking needs either this project\u2019s own connection credential (available in the session where it registered) or an account that owns this project\u2019s project key \u2014 sign in with that account, or link it from your dashboard.";
var CLAIM_BUSY=false;var AUTO_CLAIM_FOR=null;
var acctView={kind:"signed-out"};
var acctBusy=false;
function acctJoin(ep,p){return String(ep).replace(/\/+$/,"")+p;}
function acctMkInput(type,ph,ac){
var i=document.createElement("input");i.type=type;i.placeholder=ph;
if(ac)i.setAttribute("autocomplete",ac);
i.style.cssText="width:100%;box-sizing:border-box;margin-bottom:8px;padding:8px 10px;font-size:12.5px;background:"+T.bg+";color:"+T.fg+";border:1px solid "+T.br+";border-radius:8px;outline:none;font-family:inherit;";return i;}
function acctMkBtn(tx){
var b=document.createElement("button");b.type="button";b.textContent=tx;
b.style.cssText="width:100%;padding:9px 10px;font-size:12.5px;font-weight:700;background:"+T.pri+";color:"+T.bg+";border:none;border-radius:8px;cursor:pointer;font-family:inherit;";
return b;}
function acctMkGhost(tx){
var b=acctMkBtn(tx);
b.style.background="transparent";b.style.color=T.mut;
b.style.border="1px solid "+T.br;b.style.marginTop="8px";return b;}
function acctMkMsg(){
var d=document.createElement("div");
d.style.cssText="margin-top:8px;font-size:10.5px;line-height:1.5;color:"+T.mut+";";return d;}
function acctSetMsg(el,tx,color){try{el.textContent=tx;el.style.color=color||T.mut;}catch(e){}}
function acctFetch(url,init){return fetch(url,init).then(function(r){return r;});}
function acctRender(){try{
while(acctBody.firstChild)acctBody.removeChild(acctBody.firstChild);
var title=document.createElement("div");
title.style.cssText="font-size:13px;font-weight:700;color:"+T.fg+";";
title.textContent="Connect to your account";acctBody.appendChild(title);
if(!ACTX.available||!ACTX.endpoint){
var s=document.createElement("div");
s.style.cssText="font-size:10.5px;color:"+T.mut+";margin-top:6px;line-height:1.5;";
s.textContent="Sign-in is available when this page is opened on the dev machine.";
acctBody.appendChild(s);return;}
if(acctView.kind==="signed-in"){acctRenderSignedIn();return;}
if(acctView.kind==="otp"){acctRenderOtp();return;}
acctRenderSignedOut();
}catch(e){}}
function acctRenderSignedOut(){
var s=document.createElement("div");
s.style.cssText="font-size:10.5px;color:"+T.mut+";margin:6px 0 10px;line-height:1.5;";
s.textContent="Sign in with your Boosthis dashboard account to link this project directly \u2014 no project-key matching needed.";
acctBody.appendChild(s);
var email=acctMkInput("email","Email","username");acctBody.appendChild(email);
var pw=acctMkInput("password","Password","current-password");acctBody.appendChild(pw);
var btn2=acctMkBtn("Sign in");acctBody.appendChild(btn2);
var msg=acctMkMsg();acctBody.appendChild(msg);
btn2.addEventListener("click",function(){try{
if(acctBusy)return;
var em=(email.value||"").trim();var pass=pw.value||"";
if(!em||!pass){acctSetMsg(msg,"Enter your email and password.",COLORS.poor);return;}
acctBusy=true;btn2.disabled=true;btn2.textContent="Signing in\u2026";acctSetMsg(msg,"",T.mut);
acctFetch(acctJoin(ACTX.endpoint,"/auth/login"),{method:"POST",headers:{"content-type":"application/json",accept:"application/json"},body:JSON.stringify({email:em,password:pass})}).then(function(r){
pw.value="";
if(r.status===401){throw "Wrong email or password.";}
if(r.status===429){throw "Too many attempts. Try again later.";}
if(!r.ok){throw "Login failed. Please try again.";}
return r.json();}).then(function(b){
if(b&&b.otpRequired===true&&typeof b.challengeToken==="string"){
acctView={kind:"otp",challengeToken:b.challengeToken,email:em};acctRender();return;}
var tok=b&&typeof b.token==="string"?b.token:null;
var ae=b&&b.account&&typeof b.account.email==="string"?b.account.email:em;
if(!tok){throw "Login failed. Please try again.";}
SESS={token:tok,email:ae};acctView={kind:"signed-in"};acctRender();
}).catch(function(err){
acctBusy=false;try{btn2.disabled=false;btn2.textContent="Sign in";}catch(e){}
acctSetMsg(msg,typeof err==="string"?err:"Something went wrong. Please try again.",COLORS.poor);
return;}).then(function(){acctBusy=false;});
}catch(e){acctBusy=false;}});}
function acctRenderOtp(){
var s=document.createElement("div");
s.style.cssText="font-size:10.5px;color:"+T.mut+";margin:6px 0 10px;line-height:1.5;";
s.textContent="We emailed a 6-digit code to "+acctView.email+". Enter it below to finish signing in.";
acctBody.appendChild(s);
var code=acctMkInput("text","6-digit code","one-time-code");code.inputMode="numeric";acctBody.appendChild(code);
var btn2=acctMkBtn("Verify");acctBody.appendChild(btn2);
var resend=acctMkGhost("Resend code");acctBody.appendChild(resend);
var msg=acctMkMsg();acctBody.appendChild(msg);
btn2.addEventListener("click",function(){try{
if(acctBusy)return;var cv=(code.value||"").trim();
if(!cv){acctSetMsg(msg,"Enter the code from your email.",COLORS.poor);return;}
acctBusy=true;btn2.disabled=true;btn2.textContent="Verifying\u2026";acctSetMsg(msg,"",T.mut);
acctFetch(acctJoin(ACTX.endpoint,"/auth/login/otp"),{method:"POST",headers:{"content-type":"application/json",accept:"application/json"},body:JSON.stringify({challengeToken:acctView.challengeToken,code:cv})}).then(function(r){
if(r.status===401){throw "That code isn\u2019t right. Check the newest email we sent and try again.";}
if(r.status===400){acctView={kind:"signed-out"};acctRender();throw "__handled__";}
if(r.status===429){throw "Too many attempts. Try again later.";}
if(!r.ok){throw "Couldn\u2019t verify the code. Please try again.";}
return r.json();}).then(function(b){
var tok=b&&typeof b.token==="string"?b.token:null;
var ae=b&&b.account&&typeof b.account.email==="string"?b.account.email:acctView.email;
if(!tok){throw "Couldn\u2019t verify the code. Please try again.";}
SESS={token:tok,email:ae};acctView={kind:"signed-in"};acctRender();
}).catch(function(err){acctBusy=false;
if(err==="__handled__")return;
try{btn2.disabled=false;btn2.textContent="Verify";}catch(e){}
acctSetMsg(msg,typeof err==="string"?err:"Something went wrong. Please try again.",COLORS.poor);
}).then(function(){acctBusy=false;});
}catch(e){acctBusy=false;}});
resend.addEventListener("click",function(){try{
if(acctBusy)return;acctBusy=true;resend.disabled=true;acctSetMsg(msg,"",T.mut);
acctFetch(acctJoin(ACTX.endpoint,"/auth/login/otp/resend"),{method:"POST",headers:{"content-type":"application/json",accept:"application/json"},body:JSON.stringify({challengeToken:acctView.challengeToken})}).then(function(r){
if(r.ok){acctSetMsg(msg,"A new code is on its way.",COLORS.good);return;}
if(r.status===400){acctView={kind:"signed-out"};acctRender();return;}
if(r.status===429){throw "Too many resend requests. Please wait a moment.";}
if(r.status===502){throw "We couldn\u2019t send a new code right now. Try again in a few minutes.";}
throw "Couldn\u2019t resend the code. Please try again.";
}).catch(function(err){
if(typeof err==="string")acctSetMsg(msg,err,COLORS.poor);
}).then(function(){acctBusy=false;try{resend.disabled=false;}catch(e){}});
}catch(e){acctBusy=false;}});}
function acctRenderSignedIn(){
var who=document.createElement("div");
who.style.cssText="font-size:12.5px;color:"+T.fg+";font-weight:600;";
who.textContent="Signed in as "+(SESS?SESS.email:"");acctBody.appendChild(who);
var msg=acctMkMsg();
var canClaim=!!ACTX.installId;
if(canClaim){
var cs=document.createElement("div");
cs.style.cssText="font-size:10.5px;color:"+T.mut+";margin:8px 0 10px;line-height:1.5;";
cs.textContent="Link this project to your account so it appears in your dashboard.";
acctBody.appendChild(cs);
var claim=acctMkBtn(LINKED?"Linked":(CLAIM_BUSY?"Linking\u2026":"Link this project"));
claim.disabled=LINKED||CLAIM_BUSY;
acctBody.appendChild(claim);acctBody.appendChild(msg);
if(CLAIM_MSG)acctSetMsg(msg,CLAIM_MSG,CLAIM_MSG_OK?COLORS.good:COLORS.poor);
claim.addEventListener("click",function(){try{
if(acctBusy||CLAIM_BUSY||LINKED)return;acctBusy=true;claim.disabled=true;claim.textContent="Linking\u2026";acctSetMsg(msg,"",T.mut);
acctClaimRun(function(res){acctBusy=false;
CLAIM_MSG=res.msg;CLAIM_MSG_OK=res.ok===true;try{acctRender();}catch(e){}});
}catch(e){acctBusy=false;}});
acctAutoClaim();
}else{
var ns=document.createElement("div");
ns.style.cssText="font-size:10.5px;color:"+T.mut+";margin:8px 0 0;line-height:1.5;";
ns.textContent="This project hasn\u2019t registered with Boosthis yet, so it can\u2019t be linked from here.";
acctBody.appendChild(ns);acctBody.appendChild(msg);}
if(LINKED&&ACTX.installId){acctTeleToggle();}
var out=acctMkGhost("Sign out");acctBody.appendChild(out);
out.addEventListener("click",function(){try{
if(acctBusy)return;acctBusy=true;out.disabled=true;
var tok=SESS?SESS.token:null;
function done(){SESS=null;acctView={kind:"signed-out"};acctBusy=false;
AUTO_CLAIM_FOR=null;CLAIM_MSG=null;CLAIM_MSG_OK=false;TELE_KNOWN=false;
termsClear();LINKED=false;gateChecked=false;
try{if(gCheckBox){gCheckBox.style.borderColor=T.br;gCheckBox.style.background="transparent";
if(gCheckBox.firstChild)gCheckBox.firstChild.style.display="none";}}catch(e){}
acctRender();try{gateSync();}catch(e){}}
if(tok){acctFetch(acctJoin(ACTX.endpoint,"/auth/logout"),{method:"POST",headers:{authorization:"Bearer "+tok}}).then(done,done);}else{done();}
}catch(e){acctBusy=false;}});}
function acctClaimKey(){return (SESS?SESS.token:"")+"\u0001"+(ACTX.installId||"");}
function acctClaimHeaders(){var h={accept:"application/json",authorization:"Bearer "+SESS.token};
if(ACTX.deleteToken)h["x-boosthis-install-token"]=ACTX.deleteToken;return h;}
function acctTeleHeaders(){var h=acctClaimHeaders();h["content-type"]="application/json";return h;}
function acctClaimRun(cb){try{
if(CLAIM_BUSY)return;
function done(res){CLAIM_BUSY=false;try{cb(res);}catch(e){}}
if(!SESS||!ACTX.endpoint||!ACTX.installId){done({ok:false,msg:"Linking failed. Please try again."});return;}
CLAIM_BUSY=true;AUTO_CLAIM_FOR=acctClaimKey();
acctFetch(acctJoin(ACTX.endpoint,"/installs/"+encodeURIComponent(ACTX.installId)+"/claim"),{method:"POST",headers:acctClaimHeaders()}).then(function(r){
if(r.status===200){return r.json().then(function(b){return b||{};},function(){return {};}).then(function(b){
var linked=b&&b.linked===true;
LINKED=true;try{gateSync();}catch(e){}
TELE_ON=typeof b.fullTelemetry==="boolean"?b.fullTelemetry:(ACTX.fullTelemetry===true);TELE_KNOWN=true;
done({ok:true,msg:linked?"Linked. This project now appears in your dashboard.":"This project is already linked to your account."});
return;});}
if(r.status===401){return r.json().then(function(b){return b&&b.reason;},function(){return null;}).then(function(rs){
throw rs==="install_token"?ACCT_LINK_PROOF_MSG:"Your session expired. Sign in again.";});}
if(r.status===403){throw ACCT_LINK_PROOF_MSG;}
if(r.status===404){throw "This project isn\u2019t registered yet. Make sure telemetry is enabled.";}
if(r.status===409){throw "This project is already linked to a different account.";}
if(r.status===429){throw "Too many attempts. Try again later.";}
throw "Linking failed. Please try again.";
}).catch(function(err){
done({ok:false,msg:typeof err==="string"?err:"Linking failed. Please try again."});
}).then(function(){CLAIM_BUSY=false;});
}catch(e){CLAIM_BUSY=false;}}
function acctAutoClaim(){try{
if(LINKED||CLAIM_BUSY)return;
if(!SESS||!ACTX.endpoint||!ACTX.installId)return;
var k=acctClaimKey();if(AUTO_CLAIM_FOR===k)return;AUTO_CLAIM_FOR=k;
acctClaimRun(function(res){CLAIM_MSG=res.msg;CLAIM_MSG_OK=res.ok===true;
try{acctRender();}catch(e){}});
}catch(e){}}
function acctTeleToggle(){try{
var wrap=document.createElement("div");
wrap.style.cssText="margin-top:12px;padding-top:12px;border-top:1px solid "+T.br+";";
var row=document.createElement("div");
row.style.cssText="display:flex;align-items:center;justify-content:space-between;gap:10px;";
var lw=document.createElement("div");lw.style.cssText="flex:1;min-width:0;";
var lb=document.createElement("div");
lb.style.cssText="font-size:12.5px;font-weight:700;color:"+T.fg+";";lb.textContent="Full telemetry";
var sub=document.createElement("div");
sub.style.cssText="font-size:10.5px;color:"+T.mut+";margin-top:2px;line-height:1.5;";
sub.textContent="Upload the complete meter picture so your dashboard and AI can see performance. Off = private mode.";
lw.appendChild(lb);lw.appendChild(sub);
var sw=document.createElement("button");sw.type="button";
sw.setAttribute("role","switch");
sw.style.cssText="flex:0 0 auto;width:46px;height:26px;border-radius:999px;border:none;cursor:pointer;position:relative;padding:0;transition:background .15s;font-family:inherit;";
var knob=document.createElement("span");
knob.style.cssText="position:absolute;top:3px;left:3px;width:20px;height:20px;border-radius:50%;background:#fff;transition:left .15s;";
sw.appendChild(knob);
var pend=document.createElement("span");
pend.style.cssText="font-size:9px;font-weight:700;color:"+T.mut+";margin-left:8px;display:none;";pend.textContent="\u2026";
row.appendChild(lw);row.appendChild(sw);row.appendChild(pend);wrap.appendChild(row);
var tmsg=acctMkMsg();wrap.appendChild(tmsg);
function reflect(){try{
sw.style.background=TELE_ON?T.pri:T.br;
sw.setAttribute("aria-checked",TELE_ON?"true":"false");
sw.setAttribute("aria-label",(TELE_ON?"Full telemetry on":"Full telemetry off"));
knob.style.left=TELE_ON?"23px":"3px";
}catch(e){}}
reflect();
sw.addEventListener("click",function(){try{
if(TELE_BUSY||acctBusy)return;
var next=!TELE_ON;TELE_BUSY=true;acctSetMsg(tmsg,"",T.mut);
pend.style.display="inline";sw.style.opacity="0.6";
acctFetch(acctJoin(ACTX.endpoint,"/installs/"+encodeURIComponent(ACTX.installId)+"/telemetry"),{method:"POST",headers:acctTeleHeaders(),body:JSON.stringify({fullTelemetry:next})}).then(function(r){
if(r.status===200){return r.json().then(function(b){
return b&&typeof b.fullTelemetry==="boolean"?b.fullTelemetry:next;},function(){return next;}).then(function(applied){
TELE_ON=applied;TELE_KNOWN=true;reflect();
acctSetMsg(tmsg,applied?"Full telemetry on. Your dashboard and AI now see the complete picture.":"Private mode. Only issue-level signals are shared.",COLORS.good);
return;});}
if(r.status===402){return r.json().then(function(b){return b&&b.error;},function(){return null;}).then(function(er){
throw er==="billing_frozen"?"Billing is frozen \u2014 clear the balance to change this.":"Full telemetry is a Pro plan feature.";});}
if(r.status===403){throw "This project is paused \u2014 telemetry can\u2019t change while paused.";}
if(r.status===401){return r.json().then(function(b){return b&&b.reason;},function(){return null;}).then(function(rs){
throw rs==="install_token"?"This copy needs repair \u2014 press Repair in your dashboard, then reload.":"Your session expired. Sign in again.";});}
if(r.status===409){throw "This project is linked to a different account.";}
if(r.status===429){throw "Too many changes. Try again in a moment.";}
throw "Couldn\u2019t change telemetry. Please try again.";
}).catch(function(err){
acctSetMsg(tmsg,typeof err==="string"?err:"Couldn\u2019t change telemetry. Please try again.",COLORS.poor);
}).then(function(){TELE_BUSY=false;try{pend.style.display="none";sw.style.opacity="1";}catch(e){}});
}catch(e){TELE_BUSY=false;}});
acctBody.appendChild(wrap);
}catch(e){}}
var ACCT_EP={{ACCOUNT}};
var acctLoaded=false;var acctPollTimer=null;var acctPollUntil=0;
var ACCT_POLL_MS=2000;var ACCT_POLL_WINDOW_MS=120000;
function acctCtxComplete(){return !!(ACTX.installId&&ACTX.deleteToken);}
function acctPollStop(){try{if(acctPollTimer){clearInterval(acctPollTimer);acctPollTimer=null;}}catch(e){}}
function acctCtxRead(){try{
if(!ACCT_EP){acctRender();return;}
fetch(ACCT_EP,{credentials:"omit"}).then(function(r){
if(!r.ok)return null;return r.json();}).then(function(b){
if(b&&typeof b.endpoint==="string"&&b.endpoint){
ACTX.endpoint=b.endpoint;ACTX.installId=typeof b.installId==="string"?b.installId:null;
ACTX.deleteToken=typeof b.deleteToken==="string"?b.deleteToken:null;ACTX.fullTelemetry=b.fullTelemetry===true;ACTX.available=true;}
acctRender();acctTermsHref();try{gateSync();}catch(e){}
try{acctAutoClaim();}catch(e){}
if(acctCtxComplete())acctPollStop();
}).catch(function(){acctRender();try{gateSync();}catch(e){}});
}catch(e){try{acctRender();}catch(e2){}}}
function acctPollStart(){try{
if(!ACCT_EP||acctPollTimer||acctCtxComplete())return;
acctPollUntil=Date.now()+ACCT_POLL_WINDOW_MS;
acctPollTimer=setInterval(function(){try{
if(acctCtxComplete()||Date.now()>acctPollUntil){acctPollStop();return;}
acctCtxRead();}catch(e){}},ACCT_POLL_MS);
}catch(e){}}
function acctLoad(){try{
if(acctLoaded)return;acctLoaded=true;
acctCtxRead();acctPollStart();
}catch(e){try{acctRender();}catch(e2){}}}
acctRender();
var note=document.createElement("div");
note.style.cssText="margin:2px 2px 0;color:"+T.mut+";font-size:10.5px;";
note.textContent="Local dev meters \u2014 served by this project, nothing leaves it.";
var footer=document.createElement("div");
footer.style.cssText="margin:8px 2px 0;text-align:center;";
var terms=document.createElement("a");
terms.textContent="Terms & Privacy";terms.target="_blank";terms.rel="noopener";
terms.style.cssText="color:"+T.mut+";font-size:10px;text-decoration:underline;";
terms.href="https://www.boosthis.com/terms";
function acctTermsHref(){try{
if(ACTX.available&&ACTX.endpoint){
var u=new URL(ACTX.endpoint);terms.href=u.origin+"/terms";}
}catch(e){}}
var gateWrap=document.createElement("div");gateWrap.style.display="none";
var dashWrap=document.createElement("div");dashWrap.style.display="none";
dashWrap.appendChild(hero);dashWrap.appendChild(bd);dashWrap.appendChild(hl);dashWrap.appendChild(acct);dashWrap.appendChild(lg);dashWrap.appendChild(note);dashWrap.appendChild(footer);
footer.appendChild(terms);
var noticeEl=document.createElement("div");
noticeEl.style.cssText="display:none;background:"+T.card+";border:1px solid "+COLORS.poor+";border-radius:12px;padding:10px 12px;margin:0 0 10px;";
var noticeT=document.createElement("div");
noticeT.style.cssText="font-size:12px;font-weight:700;color:"+COLORS.poor+";";
var noticeB=document.createElement("div");
noticeB.style.cssText="font-size:11px;line-height:1.45;color:"+T.fg+";margin-top:3px;";
noticeEl.appendChild(noticeT);noticeEl.appendChild(noticeB);
panel.appendChild(noticeEl);
function showNotice(n){try{
var k=n&&n.kind;var ttl="",bdy="";
if(k==="install-id-not-uuid"){
ttl="Registration rejected";
bdy="Install ID must be a UUID. Mint a real UUID and restart.";}
else if(k==="not-registered"){
ttl="Not registered yet";
bdy="This app has not registered with Boosthis, so nothing is being sent. Check the project key and this app's outbound network access, then restart.";}
else if(k==="registration-unknown"){
ttl="Can't check right now";
bdy="Boosthis could not be reached to confirm whether this app is registered, so this panel cannot say either way yet. This is not a failed install and it does not mean anything stopped — it settles by itself once the check goes through. Do not change the install line's id while this is showing.";}
else if(k==="sharing-off"){
ttl="Nothing is being uploaded";
bdy="This app is registered, but sharing is off: these meters stay on this screen and your Boosthis dashboard stays empty. Turn sharing on there, or start the kit with shareMeterWithAI: true.";}
else if(k==="uploads-failing"){
ttl="Uploads are not getting through";
bdy="This app is registered and sharing is on, but the last batch of measurements did not reach Boosthis, so your dashboard is missing the most recent data."+(FAILTXT[n.reason]?" "+FAILTXT[n.reason]+".":"")+((typeof n.lost==="number"&&n.lost>0)?" Uploads lost: "+n.lost+".":"");}
if(ttl){noticeT.textContent=ttl;noticeB.textContent=(n&&n.body)||bdy;
var nc=k==="registration-unknown"?COLORS["needs-work"]:COLORS.poor;
noticeEl.style.borderColor=nc;noticeT.style.color=nc;
noticeEl.style.display="block";}
else{noticeEl.style.display="none";}
}catch(e){}}
var FAILTXT={unauthorized:"Refused \u2014 credentials rejected",rejected:"Refused \u2014 batch rejected","server-error":"Boosthis failed to store it",unreachable:"No answer \u2014 timed out or unreachable"};
var dropEl=document.createElement("div");
dropEl.style.cssText="display:none;background:"+T.card+";border:1px solid "+COLORS["needs-work"]+";border-radius:12px;padding:9px 12px;margin:0 0 10px;";
var dropT=document.createElement("div");
dropT.style.cssText="font-size:12px;font-weight:700;color:"+COLORS["needs-work"]+";";
dropT.textContent="Measurements dropped";
var dropB=document.createElement("div");
dropB.style.cssText="font-size:11px;line-height:1.45;color:"+T.fg+";margin-top:3px;";
dropEl.appendChild(dropT);dropEl.appendChild(dropB);
panel.appendChild(dropEl);
var DROPTXT={labelRejected:"route names the privacy guard refused",traceCapReached:"spans past the 20-span limit",snapshotEntryFiltered:"snapshot entries the privacy guard refused"};
function showDrops(d){try{
if(!d||typeof d.count!=="number"||d.count<=0){dropEl.style.display="none";return;}
var w=[];var cs=(d.causes&&d.causes.length)?d.causes:[];
for(var i=0;i<cs.length;i++){var t=DROPTXT[cs[i]];if(t)w.push(t);}
dropB.textContent=String(d.count)+(w.length?" \u2014 "+w.join("; "):"");
dropEl.style.display="block";
}catch(e){}}
panel.appendChild(gateWrap);panel.appendChild(dashWrap);
var gateChecked=false;var gAgree=null,gNote=null,gCheckBox=null;
function gateMk(){try{
while(gateWrap.firstChild)gateWrap.removeChild(gateWrap.firstChild);
var kick=document.createElement("div");
kick.style.cssText="font-size:10px;font-weight:600;letter-spacing:2px;color:"+T.mut+";margin:2px 2px 10px;";
kick.textContent="BOOSTHIS";gateWrap.appendChild(kick);
gateWrap.appendChild(acct);
var gt=document.createElement("div");
gt.style.cssText="font-size:20px;font-weight:800;color:"+T.fg+";margin:4px 2px 0;line-height:1.2;";
gt.textContent="Terms of Service & Privacy";gateWrap.appendChild(gt);
var gi=document.createElement("div");
gi.style.cssText="font-size:11.5px;color:"+T.mut+";margin:3px 2px 8px;";
gi.textContent="Please review and accept before using Boosthis.";gateWrap.appendChild(gi);
var gb=document.createElement("div");
gb.style.cssText="font-size:11.5px;line-height:1.55;color:"+T.mut+";margin:0 2px 6px;";
gb.textContent="Everything \u2014 liability, what Boosthis collects, AI suggestions, and project-key access \u2014 lives on the Terms & Conditions page. Open it and read it, then tick the box below to agree.";
gateWrap.appendChild(gb);
var gl=document.createElement("a");
gl.textContent="Terms & Privacy";gl.target="_blank";gl.rel="noopener";gl.href=terms.href;
gl.style.cssText="display:inline-block;color:"+T.pri+";font-size:11.5px;font-weight:600;text-decoration:underline;margin:0 2px 12px;";
gateWrap.appendChild(gl);
var crow=document.createElement("div");
crow.style.cssText="display:flex;align-items:flex-start;gap:9px;cursor:pointer;margin:0 2px 10px;";
gCheckBox=document.createElement("div");
gCheckBox.style.cssText="width:20px;height:20px;flex:0 0 20px;border-radius:5px;border:2px solid "+T.br+";display:flex;align-items:center;justify-content:center;box-sizing:border-box;";
var ck=document.createElement("span");ck.textContent="\u2713";ck.style.cssText="color:"+T.bg+";font-size:13px;font-weight:700;line-height:1;display:none;";
gCheckBox.appendChild(ck);
var cl=document.createElement("span");
cl.style.cssText="font-size:11.5px;line-height:1.45;color:"+T.fg+";";
cl.textContent="I have read and agree to the Terms of Service & Privacy Policy.";
crow.appendChild(gCheckBox);crow.appendChild(cl);gateWrap.appendChild(crow);
crow.addEventListener("click",function(){try{gateChecked=!gateChecked;
gCheckBox.style.borderColor=gateChecked?T.pri:T.br;gCheckBox.style.background=gateChecked?T.pri:"transparent";
ck.style.display=gateChecked?"block":"none";gateSync();}catch(e){}});
gNote=document.createElement("div");
gNote.style.cssText="display:none;font-size:11px;line-height:1.45;color:"+T.mut+";margin:0 2px 10px;";
gNote.textContent="Sign in above to connect this project to your dashboard before continuing.";
gateWrap.appendChild(gNote);
gAgree=document.createElement("button");gAgree.type="button";
gAgree.textContent="I Agree & Continue";
gAgree.style.cssText="width:100%;padding:11px 10px;font-size:13px;font-weight:700;border:none;border-radius:10px;cursor:pointer;font-family:inherit;";
gateWrap.appendChild(gAgree);
gAgree.addEventListener("click",function(){try{
if(!gateCanAgree())return;
termsWrite();gateSync();}catch(e){}});
var gd=document.createElement("button");gd.type="button";gd.textContent="Decline";
gd.style.cssText="width:100%;padding:8px 10px;margin-top:8px;font-size:12px;font-weight:600;background:transparent;color:"+T.mut+";border:none;cursor:pointer;font-family:inherit;";
gateWrap.appendChild(gd);
gd.addEventListener("click",function(){try{panel.style.display="none";}catch(e){}});
gateApply();
}catch(e){}}
function gateRegistered(){return !!(ACTX.available&&ACTX.endpoint&&ACTX.installId&&ACTX.deleteToken);}
function gateNeedsConnect(){return gateRegistered()&&!LINKED;}
function gateCanAgree(){return gateChecked&&!gateNeedsConnect();}
function gateApply(){try{
if(gNote)gNote.style.display=gateNeedsConnect()?"block":"none";
if(gAgree){var ok=gateCanAgree();
gAgree.style.background=ok?T.pri:T.br;gAgree.style.color=ok?T.bg:T.mut;
gAgree.style.cursor=ok?"pointer":"default";}
}catch(e){}}
var gateBuilt=false;
function gateSync(){try{
if(termsAccepted()){
gateWrap.style.display="none";dashWrap.style.display="block";
if(acct.parentNode!==dashWrap)dashWrap.insertBefore(acct,lg);
}else{
if(!gateBuilt){gateBuilt=true;gateMk();}else{
if(acct.parentNode!==gateWrap){var k=gateWrap.firstChild;
if(k&&k.nextSibling)gateWrap.insertBefore(acct,k.nextSibling);else gateWrap.appendChild(acct);}
gateApply();}
gateWrap.style.display="block";dashWrap.style.display="none";}
}catch(e){}}
var CORE={responsiveness:1,resilience:1,budget:1};
function axisRow(m){
var w=document.createElement("div");
w.style.cssText="padding:7px 0;border-bottom:1px solid "+T.br+";";
var top=document.createElement("div");
top.style.cssText="display:flex;justify-content:space-between;align-items:baseline;gap:8px;";
var l=document.createElement("span");
l.style.cssText="color:"+T.fg+";font-weight:600;font-size:12px;";l.textContent=String(m.label||m.key||"");
var rc=m.rating&&COLORS[m.rating]?COLORS[m.rating]:T.mut;
var c=document.createElement("span");
c.style.cssText="color:"+rc+";font-variant-numeric:tabular-nums;font-size:11px;text-align:right;";
c.textContent=String(m.caption||"");
top.appendChild(l);top.appendChild(c);
var bar=document.createElement("div");
bar.style.cssText="height:4px;border-radius:3px;background:"+T.br+";margin-top:5px;overflow:hidden;";
var fill=document.createElement("div");
var s=typeof m.score==="number"?Math.max(0,Math.min(100,m.score)):0;
fill.style.cssText="height:100%;border-radius:3px;width:"+s+"%;background:"+rc+";";
bar.appendChild(fill);w.appendChild(top);w.appendChild(bar);return w;}
function fillRows(box,list,empty){
while(box.firstChild)box.removeChild(box.firstChild);
if(list.length){for(var i=0;i<list.length;i++){var r=axisRow(list[i]);
if(i===list.length-1)r.style.borderBottom="none";box.appendChild(r);}}
else{var e=document.createElement("div");
e.style.cssText="padding:7px 0;color:"+T.mut+";";e.textContent=empty;box.appendChild(e);}}
function paint(p){try{
if(p&&p.lock){
if(p.lock.kind==="hidden"){try{host.style.display="none";}catch(e){}return;}
showLock(p.lock);return;}
hideLock();
try{if(host.style.display==="none")host.style.display="";}catch(e){}
showNotice(p&&p.notice);
showDrops(p&&p.drops);
try{hProj.textContent="Project: "+((p&&typeof p.project==="string"&&p.project)||"Not received from Boosthis yet");}catch(e){}
try{hInstall.textContent={{INSTALL_ID_PREFIX}}+((p&&typeof p.installId==="string"&&p.installId)||{{INSTALL_ID_UNKNOWN_TEXT}});}catch(e){}
var c=p&&p.rating?COLORS[p.rating]||T.mut:T.mut;
btn.style.borderColor=c;
num.textContent=p&&typeof p.score==="number"?String(p.score):"\u2013";
num.style.color=p&&p.rating?c:T.fg;
bTxt.textContent=p&&p.rating?(RL[p.rating]||"MEASURING"):"MEASURING";
bDot.style.background=p&&p.rating?c:T.mut;
badge.style.background=p&&p.rating?c+"1a":T.br;
badge.style.borderColor=p&&p.rating?c+"55":T.br;
badge.style.color=p&&p.rating?c:T.mut;
stat.textContent=p&&typeof p.score==="number"?String(p.sampleCount||0)+" requests measured":"measuring \u2014 handle a few requests";
var ax=(p&&p.axes)||[];var core=[],rest=[];
for(var i=0;i<ax.length;i++){(CORE[ax[i].key]?core:rest).push(ax[i]);}
fillRows(bdRows,core,"measuring\u2026 appears after a few requests");
fillRows(hlRows,rest,"measuring\u2026 appears with traffic");
}catch(e){}}
function poll(){try{paint(PANEL_DATA);}catch(e){}}
btn.addEventListener("click",function(){try{
var open=panel.style.display==="none";
panel.style.display=open?"block":"none";
if(open){gateSync();acctLoad();poll();}}catch(e){}});
panel.appendChild(lockEl);
root.appendChild(panel);root.appendChild(btn);
poll();setInterval(poll,2000);
function add(){try{(document.body||document.documentElement).appendChild(host);}catch(e){}}
if(document.body)add();else document.addEventListener("DOMContentLoaded",add);
}catch(e){}})();</script>

BUBBLE;
    }
}
