<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * Additive, display-only server meter axes for PHP — the parity sibling of the
 * Java kit's {@code MeterAxes.java} and the Go kit's {@code meter_axes.go}.
 * Every axis here ENRICHES the dashboard and is display-only by contract: none
 * of it ever touches the composite Speed score (responsiveness only, computed
 * in {@link Snapshot::buildAxes}).
 *
 * WHAT PHP CHANGES. Every other runtime holds a long-lived process, so its
 * meters can carry counters, rings and heartbeats in memory. PHP has no such
 * process: under PHP-FPM or mod_php each request starts from nothing and dies
 * at the end. So every counter, window and rolling aggregate a meter needs is
 * kept in {@link Store} (APCu when present, a locked file otherwise). Two axes
 * that the siblings measure with a background thread — schedulerLatency and
 * mcpTools — cannot be measured honestly in this model at all; see the "cannot
 * know" block below. They are deliberately never emitted.
 *
 * The axes emitted here:
 *   - confidence   FOUR scalar top-level keys (never an object), keyed off the
 *                  least-sampled SCORED route.
 *   - budget       learned per-route budgets (baseline 20 / recent 20, 1.5x).
 *   - baseline     each route graded against its OWN earlier samples.
 *   - network      OUTBOUND calls the app makes (absent forever if it makes
 *                  none — that is correct, never faked).
 *   - idle         busy request time vs elapsed wall time over the observation
 *                  window — the honest PHP reading of "wasted work while quiet".
 *   - latencyFloor the worst 10s window's p95, so one bad minute is never
 *                  averaged away.
 *   - loadDeflection how much request latency was OBSERVED to bend as
 *                  concurrency rose (only present once real concurrency exists).
 *
 * HONESTY. An axis is either genuinely measured or ABSENT from the axes map.
 * Nothing here emits a placeholder zero or an inferred value: each axis
 * self-gates on its own evidence floor and is simply missing until the floor is
 * met. GUEST SAFETY. Every public method routes through {@link Safe}; nothing
 * here can throw into the host, and all filesystem work degrades quietly.
 */
final class MeterAxes
{
    private function __construct()
    {
    }

    // ── the route-scoring gate ─────────────────────────────────────────────
    // A route is "scored" once it has at least this many samples — the SAME
    // gate Snapshot uses for the responsiveness axis, so identical traffic
    // reads as the same confidence level on every runtime.
    public const MIN_SAMPLES_FOR_AXES = 5;

    // ── confidence bands (mirror RN CONFIDENCE_CUTOFFS) ────────────────────
    private const CONFIDENCE_MEDIUM = 5;
    private const CONFIDENCE_HIGH = 20;

    // ── budget model (mirror Node budgets.ts / Python budgets.py) ──────────
    private const BUDGET_BASELINE_N = 20;
    private const BUDGET_RECENT_N = 20;
    private const BUDGET_REGRESSION_FACTOR = 1.5;

    // ── baseline anomaly bands (mirror RN computeBaselineScore) ────────────
    private const BASELINE_GOOD = 1.2;
    private const BASELINE_POOR = 2.0;
    private const BASELINE_MIN_SAMPLES = 6;
    private const BASELINE_RECENT_N = 3;
    private const BASELINE_MIN_DELTA_MS = 50;

    // ── network bands (mirror RN computeNetworkScore) ──────────────────────
    private const NETWORK_MIN_ATTEMPTS = 3;
    private const NETWORK_P75_GOOD_MS = 800;
    private const NETWORK_P75_POOR_MS = 3000;
    private const NETWORK_STALL_RATE_GOOD = 0.01;
    private const NETWORK_STALL_RATE_POOR = 0.10;
    /** Bounded ring of completed outbound durations for the p75 read. */
    private const NETWORK_RING_CAP = 512;

    // ── repeated work (same call twice inside one request) ─────────────────
    private const REPEATED_WORK_TIME_PCT_GOOD = 1;
    private const REPEATED_WORK_TIME_PCT_POOR = 20;
    private const REPEATED_WORK_BURST_GOOD = 1;
    private const REPEATED_WORK_BURST_POOR = 10;
    private const REPEATED_WORK_MIN_REQUESTS = self::MIN_SAMPLES_FOR_AXES;

    // ── idle bands (mirror RN computeIdleEfficiency thresholds) ────────────
    /** Busy request-time share of wall time (%). <=2% quiescent (100); >=20% (0). */
    private const IDLE_BUSY_PCT_GOOD = 2;
    private const IDLE_BUSY_PCT_POOR = 20;
    /** Minimum wall time observed before the axis leaves "pending" (ms). */
    private const IDLE_MIN_WALL_MS = 2000;

    // ── latencyFloor: fixed windowing (server analogue of RN Frame Floor) ──
    private const LATENCY_FLOOR_WINDOW_MS = 10000;
    private const LATENCY_FLOOR_MIN_WINDOWS = 3;
    // Latency-floor bands (ms) — a SERVER REQUEST bar, chosen here and named
    // here. This axis used to score on the shared TTI band (good 500ms), which
    // is the bar for a PAGE becoming interactive; borrowed onto a server API it
    // rated a worst-window p95 of 401ms as 100/good. These are the numbers the
    // web kit already justifies for SERVER-side processing: 200ms leaves room
    // inside a one-second page for the network and the render it still has to
    // pay for, and a second of server work has spent the whole budget before
    // anything reaches the browser. A worst-window p95 stops being "good" at
    // ~320ms.
    private const LATENCY_FLOOR_GOOD_MS = 200;
    private const LATENCY_FLOOR_POOR_MS = 1000;

    // ── loadDeflection: how much the app "bends" under concurrency ─────────
    private const DEFLECT_SCORE_GOOD_RATIO = 1.2;
    private const DEFLECT_SCORE_POOR_RATIO = 4.0;
    private const DEFLECT_BAND_GOOD_RATIO = 1.5;
    private const DEFLECT_BAND_POOR_RATIO = 3.0;
    private const DEFLECT_MIN_SAMPLES = 20;
    private const DEFLECT_MIN_BUCKET_SAMPLES = 5;
    private const DEFLECT_MIN_PEAK = 2;
    /** In-flight FLOOR for each concurrency bucket: 1, 2, 3, 5, 9, 17. A request
     *  that started with N in flight files into the highest floor <= N. */
    private const DEFLECT_BUCKET_FLOORS = [1, 2, 3, 5, 9, 17];
    /** Bounded per-bucket duration ring so the stored state never grows. */
    private const DEFLECT_BUCKET_RING_CAP = 512;
    /** Loaded samples (in-flight-at-start >= 2) needed before the merged
     *  deflectionMsPerReq slope reports (else the field is JSON null). */
    private const DEFLECT_MIN_LOADED = 10;
    /** Duration clamp before it feeds the merged soloMeanMs / least-squares
     *  accumulators (ms). The p75 bucket rings keep the RAW duration. */
    private const DEFLECT_MAX_DURATION_MS = 120000;

    // ── network outcome buckets (host-reported, label-free) ────────────────
    public const OUTCOME_OK = 0;
    public const OUTCOME_ERROR = 1;
    public const OUTCOME_TIMEOUT = 2;
    public const OUTCOME_STALL = 3;

    // ── Store keys. Every rolling aggregate lives in the cross-request store
    // because PHP keeps nothing between requests. Counts + durations only —
    // never a route label, method, URL, status, or any request content.
    private const KEY_INFLIGHT = 'meter_inflight';
    private const KEY_NETWORK = 'meter_network';
    private const KEY_IDLE = 'meter_idle';
    private const KEY_DEFLECT = 'meter_deflect';

    // ─── Rounding helpers (cross-runtime parity) ───────────────────────────

    private static function r1(float $n): float
    {
        return round($n * 10) / 10.0;
    }

    private static function r2(float $n): float
    {
        return round($n * 100) / 100.0;
    }

    // ─────────────────────────────────────────────────────────────────────
    // In-flight tracking — feeds idle AND loadDeflection.
    //
    // On the JVM/Go this is a single lock-free atomic held in the process. PHP
    // has no shared process memory across FPM workers, so the ONE in-flight
    // counter lives in the store and is bumped under the store's own lock. The
    // host wires these two into the request lifecycle (start, then end in a
    // finally) exactly as the siblings wire their atomics. The counter also
    // carries a running peak so loadDeflection knows the busiest moment seen.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * One request began. Returns the number of requests in flight AT THE START
     * of this one (this request included), so the finish path can file its
     * duration into the right concurrency bucket via {@link recordDeflection}
     * without a second observation.
     *
     * The increment is UNCONDITIONAL — there is no kill-switch gate between this
     * bump and the matching decrement in {@link noteRequestEnd}, because a gate
     * there could leak the counter forever if the flag flipped mid-request. The
     * "are we enabled" decision is made later, when a sample is actually filed.
     * Under the absolute kill-switch the store itself no-ops, so this returns 1
     * and nothing is persisted, which is safe. Never throws.
     */
    public static function noteRequestStart(): int
    {
        return (int) Safe::run(static function (): int {
            $state = Store::update(self::KEY_INFLIGHT, static function ($cur): array {
                $inFlight = is_array($cur) && isset($cur['inFlight']) ? (int) $cur['inFlight'] : 0;
                $peak = is_array($cur) && isset($cur['peak']) ? (int) $cur['peak'] : 0;
                $inFlight += 1;
                if ($inFlight > $peak) {
                    $peak = $inFlight;
                }

                return ['inFlight' => $inFlight, 'peak' => $peak];
            }, ['inFlight' => 0, 'peak' => 0]);

            $at = is_array($state) && isset($state['inFlight']) ? (int) $state['inFlight'] : 1;

            return max(1, $at);
        }, 1);
    }

    /**
     * One request finished. UNCONDITIONALLY balances the increment done by
     * {@link noteRequestStart} — no kill-switch gate, so the counter can never
     * leak on any exit path (the caller pairs the two in try/finally). Clamped
     * at 0 so a forget()/clear that reset the counter mid-request cannot drive
     * it negative. Never throws.
     */
    public static function noteRequestEnd(): void
    {
        Safe::fire(static function (): void {
            Store::update(self::KEY_INFLIGHT, static function ($cur): array {
                $inFlight = is_array($cur) && isset($cur['inFlight']) ? (int) $cur['inFlight'] : 0;
                $peak = is_array($cur) && isset($cur['peak']) ? (int) $cur['peak'] : 0;
                $inFlight -= 1;
                if ($inFlight < 0) {
                    $inFlight = 0;
                }

                return ['inFlight' => $inFlight, 'peak' => $peak];
            }, ['inFlight' => 0, 'peak' => 0]);
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // Network — outbound aggregate. Fed from the SAME observation point that
    // feeds the retry-storm detector; no second interception layer. Counts +
    // durations only — never a host, URL, status, or header.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Record one COMPLETED outbound call: its duration (ms) and whether it
     * STALLED (timed out / never returned within the kit's stall horizon). This
     * is the thin PHP surface the host wires into its HTTP client; the coarse
     * outcome buckets from the siblings collapse here to "did it stall?", which
     * is all the network axis needs (a stall is a timeout or an unreturned
     * call; both count against the stall rate). No-op under the kill-switch.
     * Never throws.
     */
    public static function recordOutbound(float $durationMs, bool $stalled): void
    {
        if (RuntimeFlags::disabled()) {
            return;
        }
        Safe::fire(static function () use ($durationMs, $stalled): void {
            $d = (int) round(max(0.0, $durationMs));
            Store::update(self::KEY_NETWORK, static function ($cur) use ($d, $stalled): array {
                $s = is_array($cur) ? $cur : [];
                $attempts = (int) ($s['attempts'] ?? 0);
                $completed = (int) ($s['completed'] ?? 0);
                $stall = (int) ($s['stall'] ?? 0);
                $worst = (int) ($s['worstMs'] ?? 0);
                $durs = is_array($s['durations'] ?? null) ? $s['durations'] : [];

                $attempts += 1;
                if ($stalled) {
                    $stall += 1;
                } else {
                    $completed += 1;
                }
                if ($d > $worst) {
                    $worst = $d;
                }
                // Only calls that produced a duration feed the p75 latency read.
                $durs[] = $d;
                if (count($durs) > self::NETWORK_RING_CAP) {
                    $durs = array_slice($durs, -self::NETWORK_RING_CAP);
                }

                return [
                    'attempts' => $attempts,
                    'completed' => $completed,
                    'stall' => $stall,
                    'worstMs' => $worst,
                    'durations' => array_values($durs),
                ];
            }, []);
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // loadDeflection — concurrency-bucketed request durations.
    //
    // Files one completed request's duration into the bucket chosen by the
    // in-flight count observed at the request's START (as returned by
    // {@link noteRequestStart}). Records ONLY a count and a duration — never a
    // route label, method, status, or any request content. The kill-switch gate
    // lives HERE (whether to file a sample), never between the in-flight
    // increment and decrement. Wired into the request lifecycle after
    // noteRequestEnd. No-op for a non-positive in-flight count. Never throws.
    // ─────────────────────────────────────────────────────────────────────

    public static function recordDeflection(int $inFlightAtStart, float $durationMs): void
    {
        if (RuntimeFlags::disabled() || $inFlightAtStart <= 0 || $durationMs < 0) {
            return;
        }
        Safe::fire(static function () use ($inFlightAtStart, $durationMs): void {
            $bucket = self::deflectBucketFor($inFlightAtStart);
            $raw = (int) round($durationMs);
            $clamped = $raw > self::DEFLECT_MAX_DURATION_MS ? self::DEFLECT_MAX_DURATION_MS : $raw;
            Store::update(self::KEY_DEFLECT, static function ($cur) use (
                $inFlightAtStart,
                $bucket,
                $raw,
                $clamped
            ): array {
                $s = self::deflectDefault();
                if (is_array($cur)) {
                    $s = array_replace($s, $cur);
                }
                // Per-bucket bounded ring of RAW durations for the p75 read.
                $rings = is_array($s['rings'] ?? null) ? $s['rings'] : [];
                $ring = is_array($rings[$bucket] ?? null) ? $rings[$bucket] : [];
                $ring[] = $raw;
                if (count($ring) > self::DEFLECT_BUCKET_RING_CAP) {
                    $ring = array_slice($ring, -self::DEFLECT_BUCKET_RING_CAP);
                }
                $rings[$bucket] = array_values($ring);
                $s['rings'] = $rings;

                $s['samples'] = (int) $s['samples'] + 1;
                $s['startSum'] = (int) $s['startSum'] + $inFlightAtStart;

                // Merged soloMeanMs / deflectionMsPerReq accumulators — fed by
                // the CLAMPED duration (the ring above kept the raw value).
                if ($inFlightAtStart === 1) {
                    $s['soloCount'] = (int) $s['soloCount'] + 1;
                    $s['soloTotalMs'] = (int) $s['soloTotalMs'] + $clamped;
                } elseif ($inFlightAtStart >= 2) {
                    $s['loadedCount'] = (int) $s['loadedCount'] + 1;
                }
                // Least-squares sums over ALL recorded samples: x =
                // inFlightAtStart, y = clamped durationMs.
                $x = (float) $inFlightAtStart;
                $y = (float) $clamped;
                $s['lsqN'] = (int) $s['lsqN'] + 1;
                $s['sumX'] = (float) $s['sumX'] + $x;
                $s['sumY'] = (float) $s['sumY'] + $y;
                $s['sumXY'] = (float) $s['sumXY'] + $x * $y;
                $s['sumX2'] = (float) $s['sumX2'] + $x * $x;

                return $s;
            }, self::deflectDefault());
        });
    }

    /** The zero-state for the loadDeflection aggregate. */
    private static function deflectDefault(): array
    {
        return [
            'rings' => [],
            'samples' => 0,
            'startSum' => 0,
            'soloCount' => 0,
            'soloTotalMs' => 0,
            'loadedCount' => 0,
            'lsqN' => 0,
            'sumX' => 0.0,
            'sumY' => 0.0,
            'sumXY' => 0.0,
            'sumX2' => 0.0,
        ];
    }

    /** The bucket index for an in-flight count: the highest floor <= count. */
    private static function deflectBucketFor(int $inFlightAtStart): int
    {
        $bucket = 0;
        foreach (self::DEFLECT_BUCKET_FLOORS as $i => $floor) {
            if ($inFlightAtStart >= $floor) {
                $bucket = $i;
            }
        }

        return $bucket;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Percentile helpers — the two cross-runtime nearest-rank rules.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Nearest-rank percentile with the FLOOR index used by budget/network/
     * latencyFloor across every runtime: idx = min(len-1, floor(len*p)), no
     * interpolation. Mutates nothing.
     *
     * @param list<int> $values
     */
    private static function percentileFloor(array $values, float $p): int
    {
        if ($values === []) {
            return 0;
        }
        sort($values);
        $idx = (int) floor(count($values) * $p);
        if ($idx > count($values) - 1) {
            $idx = count($values) - 1;
        }

        return (int) $values[$idx];
    }

    /**
     * p75 for loadDeflection with the EXACT cross-runtime nearest-rank rule so
     * Node/Go/Java/Python/PHP agree byte-for-byte: over the durations,
     * idx = ceil(0.75 * n) - 1, clamped to [0, n-1], no interpolation. This is
     * deliberately NOT the floor-based rule used by the other axes.
     *
     * @param list<int> $values
     */
    private static function deflectP75(array $values): int
    {
        if ($values === []) {
            return 0;
        }
        sort($values);
        $n = count($values);
        $idx = (int) ceil(0.75 * $n) - 1;
        if ($idx < 0) {
            $idx = 0;
        }
        if ($idx > $n - 1) {
            $idx = $n - 1;
        }

        return (int) $values[$idx];
    }

    /**
     * Median of an int list (does not mutate). 0 when empty. Mirrors RN's
     * median() used by computeBaselineScore.
     *
     * @param list<int> $values
     */
    private static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $mid = intdiv(count($values), 2);
        if (count($values) % 2 === 0) {
            return ($values[$mid - 1] + $values[$mid]) / 2.0;
        }

        return (float) $values[$mid];
    }

    // ─── confidence ────────────────────────────────────────────────────────

    /** The confidence level string from a sample count (RN confidenceLevel). */
    private static function confidenceLevel(int $sampleCount): string
    {
        if ($sampleCount <= 0) {
            return 'none';
        }
        if ($sampleCount < self::CONFIDENCE_MEDIUM) {
            return 'low';
        }
        if ($sampleCount < self::CONFIDENCE_HIGH) {
            return 'medium';
        }

        return 'high';
    }

    /** Pre-derived confidence rating (RN confidenceRatingFor). */
    private static function confidenceRatingFor(string $level): string
    {
        return match ($level) {
            'high' => Score::GOOD,
            'medium' => Score::NEEDS_WORK,
            'low' => Score::POOR,
            default => 'pending',
        };
    }

    /** Fixed confidence caption (RN CONFIDENCE_CAPTIONS / the shared copy). */
    private static function confidenceCaptionFor(string $level): string
    {
        return match ($level) {
            'high' => 'well-sampled',
            'medium' => 'moderate samples',
            'low' => 'few samples',
            default => 'no samples yet',
        };
    }

    /**
     * The count of samples backing the LEAST-sampled SCORED route — the kit's
     * "least-sampled scorable unit", where a scorable unit is one the kit is
     * willing to put a score on at all (>= {@link MIN_SAMPLES_FOR_AXES}
     * samples). Routes below the gate have no score to be confident about, so
     * they neither count nor drag confidence down. 0 when nothing is scored.
     *
     * @param array<string, list<int>> $series
     */
    private static function leastScoredRouteMounts(array $series): int
    {
        $min = -1;
        foreach ($series as $durs) {
            $c = count($durs);
            if ($c < self::MIN_SAMPLES_FOR_AXES) {
                continue;
            }
            if ($min < 0 || $c < $min) {
                $min = $c;
            }
        }

        return $min < 0 ? 0 : $min;
    }

    // ─── budget (learned per-route budgets — Node/Python model) ─────────────

    /**
     * A route is scored for budget once it has >= BUDGET_BASELINE_N samples; it
     * is on budget unless its recent p95 regressed past the baseline p95 by the
     * regression factor. Returns the budget axis as a closed 5-key map, or null
     * while pending (no route scored yet).
     *
     * @param array<string, list<int>> $series
     * @return array<string, int|string>|null
     */
    private static function computeBudget(array $series): ?array
    {
        $total = 0;
        $onBudget = 0;
        foreach ($series as $ordered) {
            if (count($ordered) < self::BUDGET_BASELINE_N) {
                continue; // still learning
            }
            $total++;
            $n = count($ordered);
            $baseline = array_slice($ordered, 0, self::BUDGET_BASELINE_N);
            $recent = array_slice($ordered, max(0, $n - self::BUDGET_RECENT_N));
            $baselineP95 = self::percentileFloor($baseline, 0.95);
            $recentP95 = self::percentileFloor($recent, 0.95);
            $regressed = $baselineP95 > 0
                && $recentP95 >= $baselineP95 * self::BUDGET_REGRESSION_FACTOR;
            if (!$regressed) {
                $onBudget++;
            }
        }
        if ($total === 0) {
            return null; // pending
        }
        $pct = (int) round(100.0 * $onBudget / $total);

        return [
            'score' => $pct,
            'rating' => Score::getRating($pct),
            'onBudget' => $onBudget,
            'total' => $total,
            'pct' => $pct,
        ];
    }

    // ─── baseline ────────────────────────────────────────────────────────

    /**
     * Each route graded against its OWN earlier samples (RN
     * computeBaselineScore: MIN_SAMPLES=6, RECENT_N=3, ratio good 1.2 / poor
     * 2.0, deltas under 50ms ignored). Reports the worst regressor. Returns a
     * closed 9-key map, or null while pending (no route has enough samples to
     * split). A route whose earlier window is too fast to divide by is counted
     * in `unscoredRoutes`, never in `scoredRoutes`; when that leaves nothing
     * compared the map abstains (`measurable` 0, rating "pending") instead of
     * scoring 100. Field names match the Java/Go siblings byte-for-byte.
     *
     * @param array<string, list<int>> $series
     * @return array<string, int|float|string>|null
     */
    private static function computeBaseline(array $series): ?array
    {
        $scoredRoutes = 0;
        $unscoredRoutes = 0;
        $anomalyCount = 0;
        $worstRatio = -1.0;
        $worstBaselineMs = 0;
        $worstCurrentMs = 0;
        foreach ($series as $durations) {
            if (count($durations) < self::BASELINE_MIN_SAMPLES) {
                continue;
            }
            $n = count($durations);
            $recent = array_slice($durations, $n - self::BASELINE_RECENT_N);
            $base = array_slice($durations, 0, $n - self::BASELINE_RECENT_N);
            $baseMed = self::median($base);
            $recentMed = self::median($recent);
            // A sub-millisecond handler records every duration as 0 (whole-ms
            // capture), so its earlier window medians to 0 and there is no
            // ratio to take. Abstain for this route and SAY SO: counting it as
            // scored here is what let a hundred-fold regression on a fast route
            // report "steady".
            if ($baseMed <= 0) {
                $unscoredRoutes++;
                continue;
            }
            $scoredRoutes++;
            $ratio = $recentMed / $baseMed;
            $delta = $recentMed - $baseMed;
            // Only a slowdown clearing BOTH the relative and absolute floor is
            // an anomaly (protects tiny-but-noisy routes).
            if ($ratio < self::BASELINE_GOOD || $delta < self::BASELINE_MIN_DELTA_MS) {
                continue;
            }
            $anomalyCount++;
            if ($worstRatio < 0 || $ratio > $worstRatio) {
                $worstRatio = $ratio;
                $worstBaselineMs = (int) round($baseMed);
                $worstCurrentMs = (int) round($recentMed);
            }
        }
        if ($scoredRoutes === 0) {
            if ($unscoredRoutes === 0) {
                return null; // pending — no route split yet
            }

            // Every route with enough samples was too fast to divide by: the
            // axis ABSTAINS (measurable 0 + rating "pending") rather than
            // reporting a perfect score, and is still uploaded — omitting it
            // would read as "warming up" forever.
            return [
                'score' => null,
                'rating' => 'pending',
                'worstRatio' => 0,
                'worstBaselineMs' => 0,
                'worstCurrentMs' => 0,
                'anomalyCount' => 0,
                'scoredRoutes' => 0,
                'unscoredRoutes' => $unscoredRoutes,
                'measurable' => 0,
            ];
        }
        if ($worstRatio < 0) {
            // Enough data, nothing regressed → steady against its own normal.
            return [
                'score' => 100,
                'rating' => Score::GOOD,
                'worstRatio' => 0,
                'worstBaselineMs' => 0,
                'worstCurrentMs' => 0,
                'anomalyCount' => 0,
                'scoredRoutes' => $scoredRoutes,
                'unscoredRoutes' => $unscoredRoutes,
                'measurable' => 1,
            ];
        }
        $score = Score::scoreMetric($worstRatio, self::BASELINE_GOOD, self::BASELINE_POOR);

        return [
            'score' => $score,
            'rating' => Score::getRating($score),
            'worstRatio' => self::r2($worstRatio),
            'worstBaselineMs' => $worstBaselineMs,
            'worstCurrentMs' => $worstCurrentMs,
            'anomalyCount' => $anomalyCount,
            'scoredRoutes' => $scoredRoutes,
            'unscoredRoutes' => $unscoredRoutes,
            'measurable' => 1,
        ];
    }

    // ─── network ───────────────────────────────────────────────────────────

    /**
     * The network axis as a closed 9-key map, or null while pending (fewer than
     * NETWORK_MIN_ATTEMPTS outbound calls — or absent forever if the app makes
     * none). Mirrors RN computeNetworkScore: score = WORSE of the p75-latency
     * and stall-rate sub-scores. Field names match the Java/Go siblings.
     *
     * PHP note: the siblings distinguish error / timeout / stall outcomes; the
     * PHP surface only knows "stalled or not", so failedCount and timeoutCount
     * are always 0 here (the fields stay for wire parity) and every stall is
     * counted in stallCount.
     *
     * @return array<string, int|float|string>|null
     */
    private static function computeNetwork(): ?array
    {
        $s = Store::get(self::KEY_NETWORK, []);
        $attempts = is_array($s) ? (int) ($s['attempts'] ?? 0) : 0;
        if ($attempts < self::NETWORK_MIN_ATTEMPTS) {
            return null; // pending — or absent forever if no outbound calls
        }
        $stall = (int) ($s['stall'] ?? 0);
        $worst = (int) ($s['worstMs'] ?? 0);
        $durs = is_array($s['durations'] ?? null) ? array_map('intval', $s['durations']) : [];

        $p75 = self::percentileFloor($durs, 0.75);
        $stallRate = $stall / $attempts;
        $latencyScore = Score::scoreMetric(
            (float) $p75,
            self::NETWORK_P75_GOOD_MS,
            self::NETWORK_P75_POOR_MS
        );
        $stallScore = Score::scoreMetric(
            $stallRate * 100.0,
            self::NETWORK_STALL_RATE_GOOD * 100.0,
            self::NETWORK_STALL_RATE_POOR * 100.0
        );
        // Either slowness OR silent stalls should turn the tile red — take the worse.
        $score = min($latencyScore, $stallScore);

        return [
            'score' => $score,
            'rating' => Score::getRating($score),
            'stallPct' => self::r1($stallRate * 100.0),
            'attemptCount' => $attempts,
            'failedCount' => 0,
            'timeoutCount' => 0,
            'stallCount' => $stall,
            'p75Ms' => $p75,
            'worstMs' => $worst,
        ];
    }

    /**
     * Same-call repetition, computed only from label-free counts and durations.
     * Absent below five watched requests (and forever when no call was
     * identifiable), so silence means "cannot tell", never a fabricated zero.
     *
     * @return array<string,int|float|string>|null
     */
    private static function computeRepeatedWork(): ?array
    {
        if (RuntimeFlags::disabled()) {
            return null;
        }
        $stats = RepeatedWork::stats();
        $watched = (int) $stats['watchedRequests'];
        if ($watched < self::REPEATED_WORK_MIN_REQUESTS) {
            return null;
        }
        $wall = (float) $stats['watchedRequestMs'];
        $redundant = (float) $stats['redundantMs'];
        $pct = $wall > 0 ? 100.0 * $redundant / $wall : 0.0;
        $repeatTimePct = max(0.0, min(100.0, self::r1($pct)));
        $worst = max(1, (int) round((float) $stats['worstRepeats']));
        $score = min(
            Score::scoreMetric(
                $repeatTimePct,
                self::REPEATED_WORK_TIME_PCT_GOOD,
                self::REPEATED_WORK_TIME_PCT_POOR
            ),
            Score::scoreMetric(
                (float) $worst,
                self::REPEATED_WORK_BURST_GOOD,
                self::REPEATED_WORK_BURST_POOR
            )
        );

        return [
            'score' => $score,
            'rating' => Score::getRating($score),
            'worstRepeats' => $worst,
            'requestsWithRepeat' => (int) $stats['requestsWithRepeat'],
            'watchedRequests' => $watched,
            'repeatTimePct' => $repeatTimePct,
        ];
    }

    // ─── idle ─────────────────────────────────────────────────────────────

    /**
     * Idle efficiency — how much work the app burned relative to how long it
     * was alive. Closed 5-key map {score,rating,idleBusyPct,windowCount,
     * windowMs}, or null while pending (< IDLE_MIN_WALL_MS of wall time seen).
     *
     * PHP-SPECIFIC READING (documented divergence). On the JVM/Go this axis is
     * "process CPU burned while ZERO requests are in flight" — it needs a
     * background heartbeat sampling CPU across idle gaps, which a per-request
     * PHP process cannot honestly do: there is no process alive between requests
     * to observe the idle gap, and no cheap per-request "CPU used while nobody
     * was being served" reading. So PHP measures the honest thing it CAN see:
     * the ratio of SUMMED served request duration (busy time) to elapsed wall
     * time across the observation window. Low means the app spent little of its
     * wall time actually working (quiescent, good); high means most of the
     * window was spent inside request handling (busy). It answers the same
     * question the siblings do — "how much of the time was real work?" — with
     * the one signal this runtime can measure without fabricating anything.
     * The bands (good 2% / poor 20%) and the wall-time floor are the siblings'.
     *
     * The busy sum and the window's first/last marks are folded in
     * {@link recordServed}, keeping the store touch off the p75/scoring path.
     *
     * @return array<string, int|float|string>|null
     */
    private static function computeIdle(): ?array
    {
        $s = Store::get(self::KEY_IDLE, []);
        if (!is_array($s)) {
            return null;
        }
        $firstMs = (int) ($s['firstMs'] ?? 0);
        $lastMs = (int) ($s['lastMs'] ?? 0);
        $busyMs = (int) ($s['busyMs'] ?? 0);
        $windows = (int) ($s['windows'] ?? 0);
        $wall = $lastMs - $firstMs;
        if ($firstMs <= 0 || $wall < self::IDLE_MIN_WALL_MS) {
            return null; // pending — not enough observed wall time yet
        }
        $busyPct = 100.0 * $busyMs / $wall;
        if ($busyPct < 0) {
            $busyPct = 0.0;
        }
        $score = Score::scoreMetric($busyPct, self::IDLE_BUSY_PCT_GOOD, self::IDLE_BUSY_PCT_POOR);

        return [
            'score' => $score,
            'rating' => Score::getRating($score),
            'idleBusyPct' => self::r1($busyPct),
            'windowCount' => $windows,
            'windowMs' => $wall,
        ];
    }

    /**
     * Fold one served request into the idle window: sum its duration into busy
     * time and stretch the window's first/last wall marks. Called from the
     * request lifecycle (the host wires it alongside {@link recordDeflection}).
     * Counts + timestamps only — never a route label. No-op under the
     * kill-switch. Never throws.
     */
    public static function recordServed(float $durationMs, ?int $atMs = null): void
    {
        if (RuntimeFlags::disabled()) {
            return;
        }
        Safe::fire(static function () use ($durationMs, $atMs): void {
            $now = $atMs ?? Telemetry::nowMs();
            $busy = (int) round(max(0.0, $durationMs));
            Store::update(self::KEY_IDLE, static function ($cur) use ($now, $busy): array {
                $s = is_array($cur) ? $cur : [];
                $first = (int) ($s['firstMs'] ?? 0);
                $last = (int) ($s['lastMs'] ?? 0);
                if ($first <= 0 || $now < $first) {
                    $first = $now;
                }
                if ($now > $last) {
                    $last = $now;
                }

                return [
                    'firstMs' => $first,
                    'lastMs' => $last,
                    'busyMs' => (int) ($s['busyMs'] ?? 0) + $busy,
                    'windows' => (int) ($s['windows'] ?? 0) + 1,
                ];
            }, []);
        });
    }

    // ─── latencyFloor ─────────────────────────────────────────────────────

    /**
     * The server analogue of the RN Frame Floor: request latencies cut into
     * fixed 10s windows keyed by floor(ts / window), each COMPLETED window's p95
     * taken, and the WORST scored on this axis's own SERVER-REQUEST band
     * (LATENCY_FLOOR_GOOD_MS/_POOR_MS above, never the page-interaction TTI
     * band) — so one bad minute is
     * never averaged away. Returns a closed 4-key map, or null while pending
     * (< LATENCY_FLOOR_MIN_WINDOWS completed windows with samples). Field names
     * match the Java/Go siblings.
     *
     * @param list<array{route:string,durationMs:int,at:int}> $samples
     * @return array<string, int|string>|null
     */
    private static function computeLatencyFloor(array $samples): ?array
    {
        if ($samples === []) {
            return null;
        }
        $now = Telemetry::nowMs();
        $currentWindow = intdiv($now, self::LATENCY_FLOOR_WINDOW_MS);
        /** @var array<int, list<int>> $byWindow */
        $byWindow = [];
        foreach ($samples as $s) {
            // A sample without a timestamp cannot be placed in a window, so it
            // is skipped rather than read as window 0 (epoch) — and reading a
            // missing key must not warn the host's error log.
            $at = (int) ($s['at'] ?? 0);
            if ($at <= 0) {
                continue;
            }
            $w = intdiv($at, self::LATENCY_FLOOR_WINDOW_MS);
            if ($w >= $currentWindow) {
                continue; // still-filling window — only COMPLETED windows scored
            }
            $byWindow[$w][] = (int) $s['durationMs'];
        }
        if (count($byWindow) < self::LATENCY_FLOOR_MIN_WINDOWS) {
            return null; // pending
        }
        $worstP95 = -1;
        foreach ($byWindow as $durs) {
            $p95 = self::percentileFloor($durs, 0.95);
            if ($p95 > $worstP95) {
                $worstP95 = $p95;
            }
        }
        if ($worstP95 < 0) {
            return null;
        }
        $score = Score::scoreMetric((float) $worstP95, self::LATENCY_FLOOR_GOOD_MS, self::LATENCY_FLOOR_POOR_MS);

        return [
            'score' => $score,
            'rating' => Score::getRating($score),
            'worstMs' => $worstP95,
            'windowCount' => count($byWindow),
        ];
    }

    // ─── loadDeflection ───────────────────────────────────────────────────

    /**
     * The loadDeflection axis as a closed 11-key map, or null while the gate is
     * unmet. Reports how much request latency was OBSERVED to bend as
     * concurrency rose: durations are filed at request START into fixed
     * in-flight buckets (1, 2, 3-4, 5-8, 9-16, 17+), and the p75 of the LOWEST
     * and HIGHEST buckets that each hold at least DEFLECT_MIN_BUCKET_SAMPLES
     * samples are compared.
     *
     * HONEST CLAIM (non-causal): this compares the app's OWN busy moments to its
     * OWN quiet moments — a measured busy-vs-quiet correlation, NOT proof that
     * concurrency caused the slowdown. The routes in each bucket are not
     * matched, so a higher ratio could equally reflect heavier requests
     * arriving together. The axis reports an observed ratio and never asserts
     * causation; it carries no caption (the server rebuilds captions).
     *
     * Gate (all must hold, else absent — a single-threaded trickle cannot
     * deflect, so reporting one would be fabricated): >= DEFLECT_MIN_SAMPLES
     * completed requests, >= DEFLECT_MIN_BUCKET_SAMPLES samples in each of the
     * two buckets used, and peakInFlight >= DEFLECT_MIN_PEAK. The last two keys
     * (soloMeanMs, deflectionMsPerReq) are ALWAYS present but NULLABLE — a JSON
     * null when not honestly measurable, never a zero fill.
     *
     * @return array<string, int|float|string|null>|null
     */
    private static function computeLoadDeflection(): ?array
    {
        $s = Store::get(self::KEY_DEFLECT, []);
        if (!is_array($s)) {
            return null;
        }
        $s = array_replace(self::deflectDefault(), $s);
        $inflight = Store::get(self::KEY_INFLIGHT, []);
        $peak = is_array($inflight) ? (int) ($inflight['peak'] ?? 0) : 0;

        $samples = (int) $s['samples'];
        $startSum = (int) $s['startSum'];
        $soloCount = (int) $s['soloCount'];
        $soloTotalMs = (int) $s['soloTotalMs'];
        $loadedCount = (int) $s['loadedCount'];
        $lsqN = (int) $s['lsqN'];
        $sumX = (float) $s['sumX'];
        $sumY = (float) $s['sumY'];
        $sumXY = (float) $s['sumXY'];
        $sumX2 = (float) $s['sumX2'];
        $rings = is_array($s['rings']) ? $s['rings'] : [];

        // Qualifying buckets (>= min samples), ascending by floor.
        $filled = [];
        foreach (self::DEFLECT_BUCKET_FLOORS as $i => $floor) {
            $ring = is_array($rings[$i] ?? null) ? array_map('intval', $rings[$i]) : [];
            $count = count($ring);
            if ($count < self::DEFLECT_MIN_BUCKET_SAMPLES) {
                continue;
            }
            $filled[] = ['floor' => $floor, 'p75' => self::deflectP75($ring)];
        }

        // Gate: enough total samples, a real peak, and two usable buckets.
        if ($samples < self::DEFLECT_MIN_SAMPLES
            || $peak < self::DEFLECT_MIN_PEAK
            || count($filled) < 2) {
            return null;
        }
        $low = $filled[0];
        $high = $filled[count($filled) - 1];
        if ($low['p75'] <= 0) {
            return null; // a zero-floor p75 would make the ratio meaningless
        }
        $ratio = self::r2($high['p75'] / $low['p75']);
        $score = Score::scoreMetric(
            $ratio,
            self::DEFLECT_SCORE_GOOD_RATIO,
            self::DEFLECT_SCORE_POOR_RATIO
        );

        $meanInFlight = $samples > 0 ? $startSum / $samples : 0.0;

        // strainAtInFlight: the LOWEST qualifying bucket floor whose OWN p75
        // crossed the runtime's existing poor latency budget (Thresholds::
        // TTI_POOR) with a STRICT > (all loadDeflection kits agree on the
        // boundary). null when no such bucket exists ("no strain observed").
        $strainAtInFlight = null;
        foreach ($filled as $b) {
            if ($b['p75'] > Thresholds::TTI_POOR) {
                $strainAtInFlight = $b['floor'];
                break;
            }
        }

        // soloMeanMs: integer-rounded mean of CLAMPED durations of samples whose
        // in-flight-at-start == 1; null when there were none.
        $soloMeanMs = $soloCount > 0 ? (int) round($soloTotalMs / $soloCount) : null;

        // deflectionMsPerReq: round(max(0, least-squares slope of clamped
        // duration on in-flight-at-start). null unless there is enough load to
        // fit a slope (loadedCount >= DEFLECT_MIN_LOADED AND >= 1 solo sample)
        // and the least-squares denominator is positive.
        $deflectionMsPerReq = null;
        if ($loadedCount >= self::DEFLECT_MIN_LOADED && $soloCount > 0) {
            $n = (float) $lsqN;
            $denom = $n * $sumX2 - $sumX * $sumX;
            if ($denom > 0) {
                $slope = ($n * $sumXY - $sumX * $sumY) / $denom;
                $deflectionMsPerReq = (int) round(max(0.0, $slope));
            }
        }

        return [
            'score' => $score,
            'rating' => self::deflectRating($ratio),
            'peakInFlight' => $peak,
            'meanInFlight' => self::r1($meanInFlight),
            'lowMs' => $low['p75'],
            'highMs' => $high['p75'],
            'ratio' => $ratio,
            'strainAtInFlight' => $strainAtInFlight,
            'samples' => $samples,
            'soloMeanMs' => $soloMeanMs,
            'deflectionMsPerReq' => $deflectionMsPerReq,
        ];
    }

    /** The loadDeflection band rating from the bend ratio: <=1.5 good, >=3.0
     *  poor, needs-work between. */
    private static function deflectRating(float $ratio): string
    {
        if ($ratio <= self::DEFLECT_BAND_GOOD_RATIO) {
            return Score::GOOD;
        }
        if ($ratio >= self::DEFLECT_BAND_POOR_RATIO) {
            return Score::POOR;
        }

        return Score::NEEDS_WORK;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Assembly
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Chronological (oldest→newest) per-route duration series, keyed by the
     * already-sanitized route label from the Samples ring.
     *
     * @param list<array{route:string,durationMs:int,at:int}> $samples
     * @return array<string, list<int>>
     */
    private static function buildRouteSeries(array $samples): array
    {
        $series = [];
        foreach ($samples as $s) {
            // A sample with no route label cannot be attributed to a route, so it
            // is skipped rather than collapsed into one nameless bucket — and
            // reading a missing key must not warn in the host's error log.
            $route = $s['route'] ?? '';
            if ($route === '') {
                continue;
            }
            $series[$route][] = (int) $s['durationMs'];
        }

        return $series;
    }

    // ─────────────────────────────────────────────────────────────────────
    // DELIBERATE "CANNOT KNOW" OMISSIONS for the PHP runtime.
    //
    // schedulerLatency and mcpTools are BOTH intentionally never emitted here.
    // The Java/Go/Python siblings both measure them from a background thread
    // that lives for the life of the process:
    //
    //   - schedulerLatency needs a daemon HEARTBEAT that wakes on a fixed
    //     interval and measures how late it actually ran, exposing runtime
    //     stalls the GC/pause axes cannot see. PHP-FPM has no event loop and no
    //     process alive between requests to run such a heartbeat, so there is
    //     nothing whose lateness we could honestly measure. Emitting a value
    //     would be fabricated.
    //
    //   - mcpTools needs the tool-call latencies to accumulate in a live
    //     in-process sampler across the server's lifetime. Nothing here holds
    //     that, and inventing per-request numbers would misrepresent the axis.
    //
    // Per the HONESTY rule, an axis PHP cannot measure is simply absent — never
    // a placeholder. These two are documented here so their absence reads as a
    // deliberate design decision, not an oversight.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Add every honestly-computable additive meter axis to {@code $axes}.
     * Pending axes are OMITTED (the server renders them "warming up"). The
     * confidence axis is emitted as FOUR scalar top-level keys (never an
     * object); mounts = 0 while unscored. Display-only: nothing here touches the
     * composite Speed score. Never throws — the whole body is guarded so kit
     * instrumentation can never destabilise the host.
     *
     * @param array<string, mixed> $axes    the axes map, mutated in place
     * @param list<array{route:string,durationMs:int,at:int}> $samples oldest-first
     */
    public static function addMeterAxes(array &$axes, array $samples): void
    {
        // Capture by-value into the guarded closure, then merge back: Safe::run
        // takes a plain callable and the reference cannot cross into it directly.
        $result = Safe::run(static function () use ($samples): array {
            $out = [];
            $series = self::buildRouteSeries($samples);

            // confidence — FOUR scalar top-level keys, keyed off the least-
            // sampled scored route. mounts = 0 when nothing is scored yet. A
            // "mount" in PHP is a measured served request (a sample in the ring).
            $mounts = self::leastScoredRouteMounts($series);
            $level = self::confidenceLevel($mounts);
            $out['confidence'] = $level;
            $out['confidenceMounts'] = $mounts;
            $out['confidenceRating'] = self::confidenceRatingFor($level);
            $out['confidenceCaption'] = self::confidenceCaptionFor($level);

            $budget = self::computeBudget($series);
            if ($budget !== null) {
                $out['budget'] = $budget;
            }
            $baseline = self::computeBaseline($series);
            if ($baseline !== null) {
                $out['baseline'] = $baseline;
            }
            $network = self::computeNetwork();
            if ($network !== null) {
                $out['network'] = $network;
            }
            $idle = self::computeIdle();
            if ($idle !== null) {
                $out['idle'] = $idle;
            }
            $latencyFloor = self::computeLatencyFloor($samples);
            if ($latencyFloor !== null) {
                HeldOpen::addFields($latencyFloor);
                $out['latencyFloor'] = $latencyFloor;
            }
            // loadDeflection — how much request latency bends as concurrency
            // rises. Absent (warming up) until the gate is met: >=20 completed
            // requests, >=5 samples in each of the two buckets compared, and a
            // peak of >=2 in flight. A single-threaded trickle cannot deflect.
            $deflection = self::computeLoadDeflection();
            if ($deflection !== null) {
                HeldOpen::addFields($deflection);
                $out['loadDeflection'] = $deflection;
            }

            // Additive security / error-visibility axes. Each reader owns its
            // own honesty gate and returns null while it has nothing truthful
            // to say (the server renders an absent axis as pending), so there
            // is no gate to repeat here. None of them ever touches the Speed
            // score — they are display-only, exactly as in the Java and Go
            // kits, where the same readers hang off this same attach point.
            $swallowed = SwallowedErrors::read();
            if ($swallowed !== null) {
                $out['swallowedErrors'] = $swallowed;
            }
            $leak = LeakWatch::read();
            if ($leak !== null) {
                $out['leakWatch'] = $leak;
            }
            $access = AccessPressure::read();
            if ($access !== null) {
                $out['accessPressure'] = $access;
            }
            $refusal = RefusalHonesty::read();
            if ($refusal !== null) {
                $out['refusalHonesty'] = $refusal;
            }
            $cookies = CookieExposure::read();
            if ($cookies !== null) {
                $out['cookieExposure'] = $cookies;
            }
            $posture = DevPosture::read();
            if ($posture !== null) {
                $out['devPosture'] = $posture;
            }

            return $out;
        }, []);

        if (is_array($result)) {
            foreach ($result as $k => $v) {
                $axes[$k] = $v;
            }
        }
        $repeatedWork = Safe::run(static fn (): ?array => self::computeRepeatedWork(), null);
        if ($repeatedWork !== null) {
            $axes['repeatedWork'] = $repeatedWork;
        }
        $dependencies = DependencyWork::dependencies();
        if ($dependencies !== null) {
            $axes['dependencies'] = $dependencies;
        }
        $dbWork = DbWork::work();
        if ($dbWork !== null) {
            $axes['dbWork'] = $dbWork;
        }
        $dbSequencing = DbWork::sequencing();
        if ($dbSequencing !== null) {
            $axes['dbSequencing'] = $dbSequencing;
        }
        $dbRowVolume = DbWork::rowVolume();
        if ($dbRowVolume !== null) {
            $axes['dbRowVolume'] = $dbRowVolume;
        }
        // NO dbPoolPressure HERE, ON PURPOSE. Every other server kit reads its
        // pool's own counters, and PHP has none to read: under php-fpm and
        // mod_php each request holds its own connection for the length of that
        // request, so there is no pool and no occupancy. Under a long-lived
        // worker SAPI (Swoole, RoadRunner, FrankenPHP) a pool can exist, but it
        // belongs to the application's own framework and PHP exposes no common
        // API for its state. See DbWork::poolPressure() for the full reasoning.
        //
        // The attach line is deliberately absent rather than written behind a
        // condition that is never true: the coverage table is derived by
        // scanning this file for attach shapes, so a dead attach would publish
        // a reading PHP never sends.
        $requestDurations = array_map(
            static fn (array $sample): int => (int) ($sample['durationMs'] ?? 0),
            $samples
        );
        $typicalRequestMs = $requestDurations === []
            ? null
            : self::median($requestDurations);
        $dependencyDistance = DependencyWork::distance($typicalRequestMs);
        if ($dependencyDistance !== null) {
            $axes['dependencyDistance'] = $dependencyDistance;
        }
        $backgroundWork = JobWork::read();
        if ($backgroundWork !== null) {
            $axes['backgroundWork'] = $backgroundWork;
        }
        $liveConnections = LiveConnections::read();
        if ($liveConnections !== null) {
            $axes['liveConnections'] = $liveConnections;
        }
        $aiCalls = AiCalls::read();
        if ($aiCalls !== null) {
            $axes['aiCalls'] = $aiCalls;
        }
    }

    /** Test seam / forget(): drop every rolling meter aggregate. */
    public static function clearMeters(): void
    {
        Safe::fire(static function (): void {
            Store::delete(self::KEY_INFLIGHT);
            Store::delete(self::KEY_NETWORK);
            Store::delete(self::KEY_IDLE);
            Store::delete(self::KEY_DEFLECT);
            HeldOpen::clear();
            RepeatedWork::clear();
            DependencyWork::clear();
            DbWork::clear();
            JobWork::clear();
            LiveConnections::clear();
            AiCalls::clear();
            RequestErrorTimerReadings::clear();
        });
    }
}
