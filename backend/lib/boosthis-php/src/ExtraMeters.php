<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * Extra infrastructure meters (PHP) — NEW, PHP-specific axes with no sibling to
 * copy. Each measures something that only matters on a per-request PHP runtime
 * (OPcache, the memory_limit / max_execution_time envelopes, the cycle
 * collector, the realpath cache), so the design and every gate is documented
 * here rather than inherited from the Java/Go kits.
 *
 * SAME CONTRACT AS THE REST OF THE KIT.
 *   - HONESTY: an axis is genuinely measured or ABSENT. Each self-gates on its
 *     own evidence floor (an extension present, an envelope actually set, enough
 *     samples, enough observed wall time) and is simply missing until met. The
 *     one deliberate exception is opcacheHealth when OPcache is present but
 *     DISABLED — that is itself an honest measured finding, so it is emitted
 *     with score 0 and a caption that says exactly that.
 *   - ADDITIVE: display-only. Nothing here touches the composite Speed score.
 *   - GUEST SAFETY: every body runs through Safe, all reads are cheap
 *     (in-memory status structs and two tiny ini lookups — no directory walks,
 *     no shelling out), and any failure degrades to an absent axis.
 *
 * PER-REQUEST LIFECYCLE. heapHeadroom, executionHeadroom and includeChurn
 * describe a single request's worst moment (peak memory, elapsed time, include
 * set size) against a ceiling or a warm-up baseline. One request tells you
 * almost nothing, so all three accumulate their samples in Store across
 * requests via {@see self::sample()} and only report once enough requests have
 * been seen. The remaining axes (opcacheHealth, gcCycles,
 * realpathCacheSaturation, fpmWorkerSaturation, jitBufferPressure) are
 * point-in-time reads of process/engine/pool state.
 *
 * PHP-FPM / JIT axes gate on SAPI + config, not on time: fpmWorkerSaturation
 * exists only inside a PHP-FPM 7.3+ worker with a status path (absent under
 * mod_php or the CLI), and jitBufferPressure only when OPcache JIT is on with a
 * non-zero buffer. Because those live sources cannot exist inside a CLI test
 * runner, each has a {@code setXForTests}/{@code resetXForTests} seam so the
 * parity harness can drive the absent / warming / honest-value paths.
 *
 * DELIBERATELY NOT SHIPPED: sessionLockWait. Measuring the wall time a request
 * blocks INSIDE session_start() on the session file/redis lock cannot be done
 * honestly under this kit's contract — the only ways to time it are to wrap the
 * host's session_start() (a host code change, forbidden) or install a custom
 * session save handler (which mutates global session state and opens/reads the
 * session, forbidden). PHP exposes no post-hoc read of the lock-wait duration.
 * A meter that could only guess would be worse than no meter, so it is dropped.
 */
final class ExtraMeters
{
    private function __construct()
    {
    }

    // ── opcacheHealth bands ─────────────────────────────────────────────────
    // Hit rate is a "higher is better" quantity, so it is scored inverted (the
    // MISS rate against the bands): a <=1% miss rate is a warm, effective cache
    // (100); a >=25% miss rate means the cache is churning or too small (0).
    private const OPCACHE_MISS_GOOD_PCT = 1.0;
    private const OPCACHE_MISS_POOR_PCT = 25.0;
    // A hit rate is only meaningful once the cache has actually been exercised.
    private const OPCACHE_MIN_LOOKUPS = 1000;

    // ── memory and collection contract bands ───────────────────────────────
    // Peak request memory as a share of the memory_limit. <=60% peak leaves
    // comfortable headroom (100); >=90% is a request one payload away from a
    // fatal OOM (0).
    private const HEAP_HEADROOM_GOOD_PCT = 60.0;
    private const HEAP_HEADROOM_POOR_PCT = 90.0;
    private const HEAP_HEADROOM_MIN_ELAPSED_MS = 5_000;
    private const MEMORY_PER_REQUEST_GOOD_KB = 4.0;
    private const MEMORY_PER_REQUEST_POOR_KB = 128.0;
    private const MEMORY_PER_REQUEST_MIN_REQUESTS = 50;
    private const GC_PRESSURE_GOOD_PCT = 1.0;
    private const GC_PRESSURE_POOR_PCT = 10.0;
    private const GC_TAX_GOOD_PCT = 1.0;
    private const GC_TAX_POOR_PCT = 10.0;
    private const GC_TAX_MIN_WINDOW_MS = 60_000;

    // ── executionHeadroom bands ─────────────────────────────────────────────
    // Request duration as a share of max_execution_time. <=50% of the budget is
    // comfortable (100); >=90% is a request about to be killed mid-flight (0).
    private const EXEC_USED_GOOD_PCT = 50.0;
    private const EXEC_USED_POOR_PCT = 90.0;
    private const MIN_EXEC_SAMPLES = 10;

    // ── gcCycles bands ──────────────────────────────────────────────────────
    // Share of roots buffer consumed at the collector threshold, as a proxy for
    // cycle-collection pressure. A low roots ratio means the app produces few
    // reference cycles (100); a high one means it leans on the cycle collector
    // (0). Lower is better.
    private const GC_ROOTS_GOOD_PCT = 25.0;
    private const GC_ROOTS_POOR_PCT = 90.0;
    // The engine only runs a cycle collection every ~10k roots, so the counters
    // are meaningless in the first seconds of a fresh process.
    private const GC_MIN_OBSERVE_MS = 60_000;

    // ── realpathCacheSaturation bands ───────────────────────────────────────
    // realpath cache bytes used vs the realpath_cache_size ini ceiling. <=70%
    // used is fine (100); >=95% means path lookups are thrashing the cache and
    // hitting the filesystem on every stat (0). Lower is better. No warm-up:
    // proximity to a fixed ceiling is honest immediately.
    private const REALPATH_USED_GOOD_PCT = 70.0;
    private const REALPATH_USED_POOR_PCT = 95.0;

    // ── fpmWorkerSaturation bands ───────────────────────────────────────────
    // Active workers as a share of the pool's total processes — the PHP-FPM
    // analogue of threadPoolSaturation. <=50% busy leaves a comfortable pool
    // (100); >=90% busy means requests are about to start queueing (0). Lower
    // is better. No warm-up: the pool utilisation is a fixed-denominator ratio
    // that is honest the instant fpm_get_status() is readable.
    private const FPM_ACTIVE_GOOD_PCT = 50.0;
    private const FPM_ACTIVE_POOR_PCT = 90.0;

    // ── jitBufferPressure bands ─────────────────────────────────────────────
    // Used share of the opcache.jit_buffer_size arena. A JIT buffer that fills
    // stops tracing new hot paths for the life of the process, so proximity to
    // the ceiling is the signal. <=70% used is fine (100); >=95% means the JIT
    // has (nearly) stopped compiling (0). Lower is better. No warm-up: nearness
    // to a fixed arena size is honest at once. This does NOT restate
    // opcacheHealth, which scores the compiled-SCRIPT cache hit rate — a
    // different arena and a different failure mode.
    private const JIT_USED_GOOD_PCT = 70.0;
    private const JIT_USED_POOR_PCT = 95.0;

    // ── includeChurn bands ──────────────────────────────────────────────────
    // Growth in the number of included/required files AFTER the app has warmed
    // up. In steady state a healthy app's include set is frozen; continued
    // growth is autoload/include churn re-hitting the filesystem on the hot
    // path. Measured as included files added since the warm-up baseline. 0 new
    // files is ideal (100); >=40 still arriving is heavy churn (0). Lower is
    // better. Frozen (0 growth) under OPcache-with-preload is the healthy case.
    private const INCLUDE_GROWTH_GOOD = 0.0;
    private const INCLUDE_GROWTH_POOR = 40.0;
    // The baseline is latched on the Nth observed request; before that the
    // include set is still legitimately loading, so the axis stays absent.
    private const INCLUDE_WARMUP_REQUESTS = 20;

    // ── crashFree bands (universal — parity with Java/Go) ───────────────────
    // Crash rate in crashes/hour. 0 -> 100, >=3/hr -> 0. A crash surfaces
    // IMMEDIATELY; zero crashes needs >=5 minutes of observation before the
    // axis will claim "crash-free".
    private const CRASHFREE_RATE_GOOD = 0.0;
    private const CRASHFREE_RATE_POOR = 3.0;
    private const CRASHFREE_MIN_WINDOW_MIN = 5;

    // ── fdSaturation bands (parity with Java/Go) ────────────────────────────
    // Open file descriptors vs the process's soft "Max open files" limit.
    // <=70% is comfortable headroom (100); >=90% is EMFILE territory (0).
    private const FD_USED_GOOD_PCT = 70.0;
    private const FD_USED_POOR_PCT = 90.0;

    // ── reliability bands (parity with Java/Go) ─────────────────────────────
    // Share of responses answered with HTTP status >= 500. <=1% (100); >=20%
    // (0). Gate: >=10 requests observed.
    private const ERR_PCT_GOOD = 1.0;
    private const ERR_PCT_POOR = 20.0;
    private const MIN_REQ_FOR_RELIABILITY = 10;

    // ── heapFragmentation bands (shared cross-runtime shape) ───────────────
    // STRANDED heap — allocator-held memory holding nothing — as a share of
    // this process's memory_limit. <=5% of the budget carried as dead weight
    // is ordinary allocator overhead (100); >=25% is a quarter of everything
    // the request may use, held and unusable (0). Gate: >=10 sampled requests.
    //
    // Deliberately NOT a share of the real heap: that denominator grows with
    // whatever the request retains, so filling memory IMPROVED the reading.
    // Measured on three matched arms, a 1 MB control read 52.2% "fragmented"
    // while a process retaining 300 MB read 0.57% — the leaker scored ninety
    // times better. Against memory_limit the same arms order correctly.
    private const FRAG_PCT_GOOD = 5.0;
    private const FRAG_PCT_POOR = 25.0;
    private const FRAGMENTATION_MIN_ELAPSED_MS = 10_000;

    // ── internedStrings bands (PHP-specific) ────────────────────────────────
    // Used share of the OPcache interned-strings buffer. A full buffer silently
    // stops new scripts being cached, so proximity to the ceiling is the signal.
    // <=70% used is fine (100); >=95% means the buffer is (nearly) full (0).
    private const INTERNED_USED_GOOD_PCT = 70.0;
    private const INTERNED_USED_POOR_PCT = 95.0;

    // ── bootCost bands (PHP-specific) ───────────────────────────────────────
    // Framework boot time: from REQUEST_TIME_FLOAT to the moment the kit
    // initialises, per request. <=50ms is a lean boot (100); >=500ms is a heavy
    // framework bootstrap before the app does any work (0). Gate: >=10 samples.
    private const BOOT_MS_GOOD = 50.0;
    private const BOOT_MS_POOR = 500.0;
    private const MIN_BOOT_SAMPLES = 10;

    // ── queueTime bands (PHP-specific) ──────────────────────────────────────
    // Wait before PHP received the request (from X-Request-Start / X-Queue-Start
    // to now). <=20ms is a request served promptly (100); >=500ms means requests
    // are queueing at the front door (0). Gate: >=10 samples.
    private const QUEUE_MS_GOOD = 20.0;
    private const QUEUE_MS_POOR = 500.0;
    private const MIN_QUEUE_SAMPLES = 10;

    // ── preloadCoverage bands (PHP-specific) ────────────────────────────────
    // Preloaded scripts as a share of all cached scripts. Higher coverage means
    // more of the app is compiled once at startup and never re-parsed, so this
    // is scored INVERTED (against the un-preloaded share): >=80% preloaded is
    // excellent coverage (100); <=20% preloaded is thin coverage (0).
    private const PRELOAD_UNCOVERED_GOOD_PCT = 20.0;
    private const PRELOAD_UNCOVERED_POOR_PCT = 80.0;

    // Any wall time below this many ms is discarded from the boot/queue series
    // as an obviously-broken sample (negative, or a clock skew past a minute).
    private const WALL_SANITY_MAX_MS = 60_000.0;

    private const SAMPLE_THROTTLE_MS = 1_000;
    private const RING_CAP = 240;

    private const BYTES_PER_MB = 1048576.0;
    private const MEM_KEY = 'extra:reqSamples';
    private const GC_START_KEY = 'extra:gcStartAt';
    private const GC_TIME_KEY = 'extra:gcTimeSamples';
    private const INCLUDE_KEY = 'extra:include';
    private const BOOT_KEY = 'extra:boot';
    private static ?int $requestStartUsedBytes = null;

    /**
     * Mark the beginning of a request so request-end retained bytes can be
     * measured. Deliberately do not reset PHP's process high-water mark:
     * peakRss depends on that lifetime value remaining intact.
     */
    public static function beginRequest(): void
    {
        self::$requestStartUsedBytes = function_exists('memory_get_usage')
            ? (int) memory_get_usage(false)
            : null;
    }
    private const QUEUE_KEY = 'extra:queue';

    /**
     * Test seams. The parity harness cannot conjure a live PHP-FPM master or a
     * JIT-enabled process inside a CLI test runner, so each live source that
     * only exists under a specific SAPI/config is injectable. When a seam is set
     * the collector reads it verbatim; when reset (default) the collector reads
     * the real runtime and self-gates to ABSENT when the source is missing.
     *
     * @var array<string,int|float>|null
     */
    private static ?array $fpmStatusOverride = null;
    private static bool $fpmStatusOverridden = false;
    /** @var array<string,int|float>|null */
    private static ?array $jitStatusOverride = null;
    private static bool $jitStatusOverridden = false;
    /** Override for count(get_included_files()) so include churn is drivable. */
    private static ?int $includeCountOverride = null;
    /**
     * Override for [openFds, maxFds] so fdSaturation is drivable off /proc.
     * @var array{0:int,1:int}|null
     */
    private static ?array $fdStatsOverride = null;
    private static bool $fdStatsOverridden = false;
    /**
     * Override for opcache_get_status(false) so internedStrings and
     * preloadCoverage are drivable inside a CLI runner with no OPcache.
     * @var array<string,mixed>|null
     */
    private static ?array $opcacheStatusOverride = null;
    private static bool $opcacheStatusOverridden = false;
    /** Override for the framework boot elapsed (ms) so bootCost is drivable. */
    private static ?float $bootMsOverride = null;
    /** Override for the queue wait (ms) so queueTime is drivable. */
    private static ?float $queueMsOverride = null;
    /** @var (callable():?string)|null */
    /** @var int|null Overrides the memory_limit read in tests. */
    private static $memoryLimitForTests = null;

    private static $opcacheCauseProbeForTests = null;
    /** @var (callable():?string)|null */
    private static $fpmCauseProbeForTests = null;
    /** @var (callable():?string)|null */
    private static $procfsCauseProbeForTests = null;
    private static bool $sourceNoticeChecked = false;

    // ── Runtime-readable source notice ─────────────────────────────────────

    public static function opcacheMissingCause(): ?string
    {
        if (self::$opcacheCauseProbeForTests !== null) {
            return Safe::run(self::$opcacheCauseProbeForTests, null);
        }

        return extension_loaded('Zend OPcache')
            ? null
            : 'the OPcache extension is not loaded';
    }

    public static function fpmMissingCause(): ?string
    {
        if (self::$fpmCauseProbeForTests !== null) {
            return Safe::run(self::$fpmCauseProbeForTests, null);
        }

        return function_exists('fpm_get_status')
            ? null
            : 'fpm_get_status() is unavailable because this SAPI is not PHP-FPM';
    }

    public static function procfsMissingCause(): ?string
    {
        if (self::$procfsCauseProbeForTests !== null) {
            return Safe::run(self::$procfsCauseProbeForTests, null);
        }

        $fds = @scandir('/proc/self/fd');
        $limits = @file_get_contents('/proc/self/limits');

        return is_array($fds) && is_string($limits)
            ? null
            : '/proc/self is unreadable';
    }

    public static function unreadableSourcesLine(
        ?string $opcacheCause,
        ?string $fpmCause,
        ?string $procfsCause
    ): ?string {
        $parts = [];
        if ($opcacheCause !== null) {
            $parts[] = 'OPcache health and interned strings (' . $opcacheCause
                . '): compiled-script cache health and interned-string usage come from the OPcache extension';
        }
        if ($fpmCause !== null) {
            $parts[] = 'worker saturation (' . $fpmCause
                . '): PHP-FPM worker capacity and activity come from fpm_get_status()';
        }
        if ($procfsCause !== null) {
            $parts[] = 'descriptor headroom (' . $procfsCause
                . '): open descriptors and their soft limit come from /proc/self/fd and /proc/self/limits';
        }
        if ($parts === []) {
            return null;
        }
        $tail = '. ' . (count($parts) === 1 ? 'That one meter stays' : 'Those meters stay')
            . ' absent for the life of this process; nothing else about Boosthis is affected.';
        if (count($parts) === 1) {
            return '[boosthis] This PHP runtime cannot report ' . $parts[0] . $tail;
        }

        return '[boosthis] This PHP runtime cannot report ' . count($parts)
            . " of Boosthis's readings — " . implode('; ', $parts) . $tail;
    }

    public static function announceUnreadableSources(): void
    {
        if (self::$sourceNoticeChecked) {
            return;
        }
        self::$sourceNoticeChecked = true;
        $line = Safe::run(
            static fn (): ?string => self::unreadableSourcesLine(
                self::opcacheMissingCause(),
                self::fpmMissingCause(),
                self::procfsMissingCause()
            ),
            null
        );
        if ($line !== null) {
            Safe::fire(static function () use ($line): void {
                StartAnnounce::sayAfterStartupLine($line);
            });
        }
    }

    public static function setOpcacheCauseProbeForTests(?callable $probe): void
    {
        self::$opcacheCauseProbeForTests = $probe;
    }

    public static function setFpmCauseProbeForTests(?callable $probe): void
    {
        self::$fpmCauseProbeForTests = $probe;
    }

    public static function setProcfsCauseProbeForTests(?callable $probe): void
    {
        self::$procfsCauseProbeForTests = $probe;
    }

    public static function clearSourceCauseProbesForTests(): void
    {
        self::$opcacheCauseProbeForTests = null;
        self::$fpmCauseProbeForTests = null;
        self::$procfsCauseProbeForTests = null;
    }

    public static function resetSourceNoticeForTests(): void
    {
        self::$sourceNoticeChecked = false;
    }

    /**
     * Record ONE per-request sample into Store. Called once per request. Guest-
     * safe, throttled, never throws. Captures this request's peak memory and its
     * elapsed wall time (the two envelope axes need a cross-request series) and
     * latches the first-seen timestamp used by the gcCycles observation gate.
     */
    public static function sample(bool $includeRequestDuration = true): void
    {
        if (RuntimeFlags::disabled()) {
            return;
        }
        Safe::fire(static function () use ($includeRequestDuration): void {
            $now = Telemetry::nowMs();

            // Peak real memory (true = including allocator overhead), the honest
            // number to compare against memory_limit.
            $peakBytes = function_exists('memory_get_peak_usage')
                ? (int) memory_get_peak_usage(true)
                : null;

            // Real vs used heap at request end, for heapFragmentation: real =
            // the allocator-held total, used = what the app actually occupies;
            // the gap is allocator-held-but-unused space.
            $realBytes = null;
            $usedBytes = null;
            if (function_exists('memory_get_usage')) {
                $realBytes = (int) memory_get_usage(true);
                $usedBytes = (int) memory_get_usage(false);
            }

            // Request duration: from PHP's own request-start clock to now. Under
            // CLI there is no request, so REQUEST_TIME_FLOAT may be absent —
            // then the execution axis simply never gates.
            $durationMs = null;
            $startFloat = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
            if ($includeRequestDuration && is_numeric($startFloat)) {
                $elapsed = (microtime(true) - (float) $startFloat) * 1000.0;
                if ($elapsed >= 0) {
                    $durationMs = $elapsed;
                }
            }

            $retainedBytes = $usedBytes !== null && self::$requestStartUsedBytes !== null
                ? $usedBytes - self::$requestStartUsedBytes
                : null;
            Store::update(self::MEM_KEY, static function ($cur) use (
                $now,
                $peakBytes,
                $durationMs,
                $realBytes,
                $usedBytes,
                $retainedBytes
            ) {
                $ring = is_array($cur) ? $cur : [];
                $last = $ring[count($ring) - 1] ?? null;
                if (is_array($last) && isset($last['at'])
                    && $now - (int) $last['at'] < self::SAMPLE_THROTTLE_MS) {
                    return $ring;
                }
                $entry = ['at' => $now];
                if ($peakBytes !== null) {
                    $entry['peakBytes'] = $peakBytes;
                }
                if ($realBytes !== null && $realBytes > 0 && $usedBytes !== null) {
                    $entry['realBytes'] = $realBytes;
                    $entry['usedBytes'] = $usedBytes;
                }
                if ($retainedBytes !== null) {
                    $entry['retainedBytes'] = $retainedBytes;
                }
                if ($durationMs !== null) {
                    $entry['durMs'] = $durationMs;
                }
                $ring[] = $entry;
                while (count($ring) > self::RING_CAP) {
                    array_shift($ring);
                }

                return array_values($ring);
            }, []);

            // Latch the first observation time for the gcCycles wall-clock gate.
            // set-if-absent: only the first request writes it.
            if (Store::get(self::GC_START_KEY, null) === null) {
                Store::set(self::GC_START_KEY, $now);
            }
            self::sampleGcTime($now);

            // includeChurn: count only — never the file list. Track the request
            // number and, once warmed up, the frozen baseline count so growth
            // after warm-up is measurable across requests.
            $included = self::includedFileCount();
            if ($included !== null) {
                Store::update(self::INCLUDE_KEY, static function ($cur) use ($included): array {
                    $s = is_array($cur) ? $cur : [];
                    $reqs = (int) ($s['reqs'] ?? 0) + 1;
                    $s['reqs'] = $reqs;
                    $s['last'] = $included;
                    // Latch the steady-state baseline on the warm-up request,
                    // once the app's boot-time include storm is over.
                    if ($reqs === self::INCLUDE_WARMUP_REQUESTS && !isset($s['baseline'])) {
                        $s['baseline'] = $included;
                    }
                    // Track the high-water mark of new includes seen since the
                    // baseline so a transient spike is not averaged away.
                    if (isset($s['baseline'])) {
                        $growth = $included - (int) $s['baseline'];
                        if ($growth < 0) {
                            $growth = 0;
                        }
                        if ($growth > (int) ($s['peakGrowth'] ?? 0)) {
                            $s['peakGrowth'] = $growth;
                        }
                    }

                    return $s;
                }, []);
            }

            // bootCost: framework boot time, from REQUEST_TIME_FLOAT to the
            // moment the kit initialises. Absent under CLI (no request clock).
            // Absurd values (negative, or a clock skew past a minute) are
            // dropped rather than stored — never a fabricated sample.
            $bootMs = self::bootElapsedMs();
            if ($bootMs !== null && $bootMs >= 0 && $bootMs <= self::WALL_SANITY_MAX_MS) {
                self::pushDuration(self::BOOT_KEY, $bootMs, $now);
            }

            // queueTime: wait before PHP received the request, derived from the
            // proxy's X-Request-Start / X-Queue-Start header. Only the DERIVED
            // duration is stored — never the header text. Negative or absurd
            // (>60s) samples are dropped; the axis stays absent forever when the
            // header never appears.
            $queueMs = self::queueWaitMs();
            if ($queueMs !== null && $queueMs >= 0 && $queueMs <= self::WALL_SANITY_MAX_MS) {
                self::pushDuration(self::QUEUE_KEY, $queueMs, $now);
            }
        });
    }

    /**
     * Append one duration (ms) to a bounded, throttled per-request ring under
     * $key. Shares the sampling cadence and cap of the memory ring. Guest-safe:
     * called only from within sample()'s Safe wrapper.
     */
    private static function pushDuration(string $key, float $ms, int $now): void
    {
        Store::update($key, static function ($cur) use ($ms, $now) {
            $ring = is_array($cur) ? $cur : [];
            $last = $ring[count($ring) - 1] ?? null;
            if (is_array($last) && isset($last['at'])
                && $now - (int) $last['at'] < self::SAMPLE_THROTTLE_MS) {
                return $ring;
            }
            $ring[] = ['at' => $now, 'ms' => $ms];
            while (count($ring) > self::RING_CAP) {
                array_shift($ring);
            }

            return array_values($ring);
        }, []);
    }

    /**
     * Attach every currently-available extra meter to $axes. A warming-up or
     * unmeasurable axis is omitted (rendered pending by the server). Guest-safe:
     * never throws.
     *
     * @param array<string,mixed> $axes
     */
    public static function addExtraMeters(array &$axes): void
    {
        if (RuntimeFlags::disabled()) {
            return;
        }
        $add = static function (array &$axes, string $key, ?array $axis): void {
            if ($axis !== null) {
                $axes[$key] = $axis;
            }
        };
        Safe::fire(static function () use (&$axes, $add): void {
            $ring = self::ring();
            $add($axes, 'opcacheHealth', self::opcacheHealth());
            $add($axes, 'peakRss', self::peakRss());
            $add($axes, 'memoryPerRequest', self::memoryPerRequest($ring));
            $add($axes, 'heapHeadroom', self::heapHeadroom($ring));
            $add($axes, 'gcPressure', self::gcPressure());
            $add($axes, 'gcTax', self::gcTax());
            $add($axes, 'executionHeadroom', self::executionHeadroom($ring));
            $add($axes, 'gcCycles', self::gcCycles());
            $add($axes, 'realpathCacheSaturation', self::realpathCacheSaturation());
            $add($axes, 'fpmWorkerSaturation', self::fpmWorkerSaturation());
            $add($axes, 'jitBufferPressure', self::jitBufferPressure());
            $add($axes, 'includeChurn', self::includeChurn());
            $add($axes, 'crashFree', self::crashFree());
            $add($axes, 'fdSaturation', self::fdSaturation());
            $add($axes, 'reliability', self::reliability());
            $add($axes, 'heapFragmentation', self::heapFragmentation($ring));
            $add($axes, 'internedStrings', self::internedStrings());
            $add($axes, 'bootCost', self::bootCost());
            $add($axes, 'queueTime', self::queueTime());
            $add($axes, 'preloadCoverage', self::preloadCoverage());
        });
    }

    // ── Axes ────────────────────────────────────────────────────────────────

    /**
     * opcacheHealth — is the compiled-script cache present, on, and effective?
     * Source: opcache_get_status(false). Gate: the extension is present, and at
     * least 1000 hits+misses have accrued so the ratio means something.
     *
     * HONEST DISABLED FINDING. If OPcache is compiled in but turned off, that is
     * a real, measured deployment problem (PHP recompiling every file on every
     * request), so the axis is emitted with score 0 and a caption naming it —
     * NOT omitted. It is omitted only when the extension is entirely absent
     * (nothing to measure) or the warm-up floor has not been reached.
     *
     * @return array<string,mixed>|null
     */
    private static function opcacheHealth(): ?array
    {
        if (!function_exists('opcache_get_status')) {
            // Extension not compiled in — nothing to measure, honest absence.
            return null;
        }
        $enabledIni = @ini_get('opcache.enable');
        $status = @opcache_get_status(false);
        // opcache_get_status returns false when OPcache is disabled at runtime.
        if ($status === false || !is_array($status)
            || empty($status['opcache_enabled'])) {
            // Present but DISABLED — an honest measured finding, score 0. Only
            // surface it if the host actually intended to configure OPcache
            // (the ini directive exists); otherwise treat as absent.
            if ($enabledIni === false || $enabledIni === null) {
                return null;
            }

            return [
                'score' => 0,
                'rating' => Score::getRating(0),
                'hitRatePct' => 0.0,
                'restarts' => 0,
                'wastedPct' => 0.0,
                'cachedScripts' => 0,
                'caption' => 'OPcache is present but disabled · scripts recompiled every request',
            ];
        }

        $stats = is_array($status['opcache_statistics'] ?? null) ? $status['opcache_statistics'] : [];
        $hits = (int) ($stats['hits'] ?? 0);
        $misses = (int) ($stats['misses'] ?? 0);
        $lookups = $hits + $misses;
        if ($lookups < self::OPCACHE_MIN_LOOKUPS) {
            // Not exercised enough yet — the ratio would be noise.
            return null;
        }
        $hitRate = isset($stats['opcache_hit_rate'])
            ? (float) $stats['opcache_hit_rate']
            : 100.0 * $hits / $lookups;
        $missPct = 100.0 - $hitRate;

        // Restarts across all causes: OOM, hash-collision and manual are all
        // signs of a cache under stress.
        $restarts = (int) ($stats['oom_restarts'] ?? 0)
            + (int) ($stats['hash_restarts'] ?? 0)
            + (int) ($stats['manual_restarts'] ?? 0);
        $cachedScripts = (int) ($stats['num_cached_scripts'] ?? 0);

        $mem = is_array($status['memory_usage'] ?? null) ? $status['memory_usage'] : [];
        $wastedPct = isset($mem['current_wasted_percentage'])
            ? (float) $mem['current_wasted_percentage']
            : (float) ($mem['wasted_percentage'] ?? 0.0);

        $score = Score::scoreMetric($missPct, self::OPCACHE_MISS_GOOD_PCT, self::OPCACHE_MISS_POOR_PCT);

        return [
            'score' => $score,
            'rating' => Score::getRating($score),
            'hitRatePct' => self::r1($hitRate),
            'restarts' => $restarts,
            'wastedPct' => self::r1($wastedPct),
            'cachedScripts' => $cachedScripts,
            'caption' => self::numStr(self::r1($hitRate)) . '% hit rate · '
                . $cachedScripts . ' scripts cached · ' . $restarts . ' restarts',
        ];
    }

    /**
     * heapHeadroom — the worst per-request peak memory as a share of the
     * memory_limit ceiling. Omitted when memory_limit is -1 (unlimited: there is
     * no wall to be near). Gate: >=10 sampled requests.
     *
     * @param list<array<string,int|float>> $ring
     * @return array<string,mixed>|null
     */
    private static function heapHeadroom(array $ring): ?array
    {
        $limitBytes = self::iniBytes(@ini_get('memory_limit'));
        if ($limitBytes === null || $limitBytes <= 0) {
            // -1 (unlimited) or unreadable — no ceiling to measure against.
            return null;
        }
        $peaks = [];
        foreach ($ring as $s) {
            if (isset($s['peakBytes'])) {
                $peaks[] = (int) $s['peakBytes'];
            }
        }
        $timed = array_values(array_filter($ring, static fn ($s): bool => isset($s['at'])));
        if ($timed === []
            || (int) $timed[count($timed) - 1]['at'] - (int) $timed[0]['at']
                < self::HEAP_HEADROOM_MIN_ELAPSED_MS) {
            return null;
        }
        $worst = max($peaks);
        $usedPct = 100.0 * $worst / $limitBytes;
        $score = Score::scoreMetric(
            $usedPct,
            self::HEAP_HEADROOM_GOOD_PCT,
            self::HEAP_HEADROOM_POOR_PCT
        );
        $peakMb = self::r0($worst / self::BYTES_PER_MB);
        $limitMb = self::r0($limitBytes / self::BYTES_PER_MB);

        return [
            'score' => $score,
            'rating' => Score::getRating($score),
            'usedMb' => $peakMb,
            'limitMb' => $limitMb,
            'usedPct' => self::r1($usedPct),
            'elapsedMs' => (int) $timed[count($timed) - 1]['at'] - (int) $timed[0]['at'],
            'caption' => $peakMb . '/' . $limitMb . ' MB peak · '
                . self::numStr(self::r1($usedPct)) . '% used',
        ];
    }

    /** Raw process high-water mark. PHP keeps this exact value for us. */
    private static function peakRss(): ?array
    {
        if (!function_exists('memory_get_peak_usage')) {
            return null;
        }
        $peak = (int) memory_get_peak_usage(true);
        $rss = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : $peak;
        return [
            'rating' => Score::NOT_SCORED,
            'peakRssMb' => self::r1($peak / self::BYTES_PER_MB),
            'rssMb' => self::r1($rss / self::BYTES_PER_MB),
            'sampleCount' => 1,
            'exact' => 1,
        ];
    }

    /** Mean bytes retained when a completed request returns to the kit. */
    private static function memoryPerRequest(array $ring): ?array
    {
        $values = [];
        $times = [];
        foreach ($ring as $s) {
            if (isset($s['retainedBytes'], $s['at'])) {
                $values[] = max(0, (int) $s['retainedBytes']);
                $times[] = (int) $s['at'];
            }
        }
        if (count($values) < self::MEMORY_PER_REQUEST_MIN_REQUESTS) {
            return null;
        }
        $avgKb = array_sum($values) / count($values) / 1024.0;
        $score = Score::scoreMetric(
            $avgKb,
            self::MEMORY_PER_REQUEST_GOOD_KB,
            self::MEMORY_PER_REQUEST_POOR_KB
        );
        return [
            'score' => $score,
            'rating' => Score::getRating($score),
            'avgKb' => self::r1($avgKb),
            'requestCount' => count($values),
            'windowMin' => self::r1((max($times) - min($times)) / 60_000.0),
        ];
    }

    /** Store PHP 8.3's cumulative collector/application clocks. */
    private static function sampleGcTime(int $now): void
    {
        if (PHP_VERSION_ID < 80300 || !function_exists('gc_status')) {
            return;
        }
        $s = @gc_status();
        if (!is_array($s) || !isset($s['collector_time'], $s['application_time'])) {
            return;
        }
        Store::update(self::GC_TIME_KEY, static function ($cur) use ($now, $s): array {
            $ring = is_array($cur) ? $cur : [];
            $ring[] = [
                'at' => $now,
                'collectorMs' => (float) $s['collector_time'] * 1000.0,
                'applicationMs' => (float) $s['application_time'] * 1000.0,
                'count' => (int) ($s['runs'] ?? 0),
            ];
            while (count($ring) > self::RING_CAP) {
                array_shift($ring);
            }
            return array_values($ring);
        }, []);
    }

    /** @return array<string,mixed> */
    private static function gcVersionAbsence(): array
    {
        return [
            'rating' => Score::NOT_AVAILABLE,
            'present' => 1,
            'measurable' => 0,
            'reason' => 'PHP ' . PHP_VERSION . ' does not publish gc_status collector_time (requires PHP 8.3+)',
        ];
    }

    private static function gcPressure(): ?array
    {
        if (PHP_VERSION_ID < 80300) {
            return self::gcVersionAbsence();
        }
        $ring = Store::get(self::GC_TIME_KEY, []);
        if (!is_array($ring) || $ring === []) {
            return null;
        }
        $last = $ring[count($ring) - 1];
        if ((int) ($last['count'] ?? 0) < 1 || (float) ($last['applicationMs'] ?? 0) <= 0) {
            return null;
        }
        $pct = 100.0 * (float) $last['collectorMs'] / (float) $last['applicationMs'];
        $score = Score::scoreMetric($pct, self::GC_PRESSURE_GOOD_PCT, self::GC_PRESSURE_POOR_PCT);
        return [
            'score' => $score,
            'rating' => Score::getRating($score),
            'gcPct' => self::r1($pct),
            'gcCount' => (int) $last['count'],
            'windowMin' => self::r1((float) $last['applicationMs'] / 60_000.0),
        ];
    }

    private static function gcTax(): ?array
    {
        if (PHP_VERSION_ID < 80300) {
            return self::gcVersionAbsence();
        }
        $ring = Store::get(self::GC_TIME_KEY, []);
        if (!is_array($ring) || count($ring) < 2) {
            return null;
        }
        $last = $ring[count($ring) - 1];
        $first = null;
        foreach ($ring as $candidate) {
            if ((int) $last['at'] - (int) $candidate['at'] >= self::GC_TAX_MIN_WINDOW_MS) {
                $first = $candidate;
            }
        }
        if ($first === null) {
            return null;
        }
        $wallMs = (int) $last['at'] - (int) $first['at'];
        $gcMs = (float) $last['collectorMs'] - (float) $first['collectorMs'];
        $count = (int) $last['count'] - (int) $first['count'];
        if ($gcMs < 0 || $count < 0) {
            return null;
        }
        $pct = 100.0 * $gcMs / $wallMs;
        $score = Score::scoreMetric($pct, self::GC_TAX_GOOD_PCT, self::GC_TAX_POOR_PCT);
        return [
            'score' => $score,
            'rating' => Score::getRating($score),
            'gcPct' => self::r1($pct),
            'gcCount' => $count,
            'windowMin' => self::r1($wallMs / 60_000.0),
        ];
    }

    /**
     * executionHeadroom — how close requests run to the max_execution_time
     * budget: the worst and p95 request duration as a share of the limit.
     * Omitted when max_execution_time is 0 (no limit, e.g. under CLI). Gate:
     * >=10 sampled requests.
     *
     * @param list<array<string,int|float>> $ring
     * @return array<string,mixed>|null
     */
    private static function executionHeadroom(array $ring): ?array
    {
        $limitSec = @ini_get('max_execution_time');
        if ($limitSec === false || $limitSec === null) {
            return null;
        }
        $limitMs = (int) $limitSec * 1000;
        if ($limitMs <= 0) {
            // 0 = unlimited — nothing to be near.
            return null;
        }
        $durs = [];
        foreach ($ring as $s) {
            if (isset($s['durMs'])) {
                $durs[] = (float) $s['durMs'];
            }
        }
        if (count($durs) < self::MIN_EXEC_SAMPLES) {
            return null;
        }
        sort($durs);
        $worstMs = $durs[count($durs) - 1];
        $p95Ms = self::percentile($durs, 0.95);
        $worstPct = 100.0 * $worstMs / $limitMs;
        $p95Pct = 100.0 * $p95Ms / $limitMs;
        // Score the worst case — the request most at risk of being killed.
        $score = Score::scoreMetric($worstPct, self::EXEC_USED_GOOD_PCT, self::EXEC_USED_POOR_PCT);

        return [
            'score' => $score,
            'rating' => Score::getRating($score),
            'worstPct' => self::r1($worstPct),
            'p95Pct' => self::r1($p95Pct),
            'limitMs' => $limitMs,
            'worstMs' => (int) round($worstMs),
            'sampleCount' => count($durs),
            'caption' => self::numStr(self::r1($worstPct)) . '% of the '
                . (int) $limitSec . 's budget at worst · p95 '
                . self::numStr(self::r1($p95Pct)) . '%',
        ];
    }

    /**
     * gcCycles — pressure on PHP's cycle collector. Source: gc_status(). The
     * roots buffer fills with candidate cycles between collections; the ratio of
     * roots to the buffer size is an honest proxy for how hard the app leans on
     * cycle collection. Gate: >=60s of observation (the collector fires roughly
     * every 10k roots, so early counters are meaningless).
     *
     * @return array<string,mixed>|null
     */
    private static function gcCycles(): ?array
    {
        if (!function_exists('gc_status')) {
            return null;
        }
        $startAt = Store::get(self::GC_START_KEY, null);
        if (!is_int($startAt) || Telemetry::nowMs() - $startAt < self::GC_MIN_OBSERVE_MS) {
            return null;
        }
        $gc = @gc_status();
        if (!is_array($gc)) {
            return null;
        }
        $runs = (int) ($gc['runs'] ?? 0);
        $collected = (int) ($gc['collected'] ?? 0);
        $roots = (int) ($gc['roots'] ?? 0);
        // buffer_size appeared in PHP 7.3; without it fall back to the default
        // engine root threshold so the ratio is still honest.
        $bufferSize = (int) ($gc['buffer_size'] ?? 0);
        if ($bufferSize <= 0) {
            $bufferSize = (int) ($gc['threshold'] ?? 10001);
        }
        if ($bufferSize <= 0) {
            return null;
        }
        $rootsPct = min(100.0, 100.0 * $roots / $bufferSize);
        $score = Score::scoreMetric($rootsPct, self::GC_ROOTS_GOOD_PCT, self::GC_ROOTS_POOR_PCT);

        return [
            'score' => $score,
            'rating' => Score::getRating($score),
            'runs' => $runs,
            'collected' => $collected,
            'rootsPct' => self::r1($rootsPct),
            'caption' => $runs . ' cycle collections · ' . $collected . ' objects freed · '
                . self::numStr(self::r1($rootsPct)) . '% roots buffer',
        ];
    }

    /**
     * realpathCacheSaturation — how full the realpath cache is. Source:
     * realpath_cache_size() vs the realpath_cache_size ini ceiling. A saturated
     * cache means every path lookup re-stats the filesystem. Gate: the ini value
     * is readable. No warm-up: nearness to a fixed ceiling is honest at once.
     *
     * @return array<string,mixed>|null
     */
    private static function realpathCacheSaturation(): ?array
    {
        if (!function_exists('realpath_cache_size')) {
            return null;
        }
        $capBytes = self::iniBytes(@ini_get('realpath_cache_size'));
        if ($capBytes === null || $capBytes <= 0) {
            return null;
        }
        $usedBytes = (int) @realpath_cache_size();
        if ($usedBytes < 0) {
            return null;
        }
        $usedPct = min(100.0, 100.0 * $usedBytes / $capBytes);
        $entries = function_exists('realpath_cache_get')
            ? count(@realpath_cache_get() ?: [])
            : 0;
        $score = Score::scoreMetric($usedPct, self::REALPATH_USED_GOOD_PCT, self::REALPATH_USED_POOR_PCT);

        return [
            'score' => $score,
            'rating' => Score::getRating($score),
            'usedPct' => self::shareRound($usedPct),
            'usedBytes' => $usedBytes,
            'capBytes' => $capBytes,
            'entries' => $entries,
            'caption' => self::numStr(self::shareRound($usedPct)) . '% realpath cache used · '
                . $usedBytes . '/' . $capBytes . ' bytes · ' . $entries . ' paths',
        ];
    }

    /**
     * fpmWorkerSaturation — the PHP-FPM pool's worker pressure, the PHP
     * equivalent of the JVM/Go threadPoolSaturation axis. Source:
     * fpm_get_status(), which exists ONLY inside a PHP-FPM 7.3+ worker with
     * pm.status_path configured. Under mod_php, the CLI, or an FPM without a
     * status path the function does not exist and the axis is honestly ABSENT
     * (there is no pool to measure). No warm-up: every field is a point-in-time
     * pool reading with a fixed denominator.
     *
     * Scored on active/total worker utilisation (queueing is imminent once the
     * pool is saturated). The listen-queue depth, the lifetime "max children
     * reached" count and the slow-request count ride along as context counters.
     * Counts only — never a pool name, a listen address, or any request.
     *
     * @return array<string,mixed>|null
     */
    private static function fpmWorkerSaturation(): ?array
    {
        $status = self::fpmStatus();
        if ($status === null) {
            return null; // not a PHP-FPM worker with a status path — honest absence
        }
        $active = (int) ($status['active processes'] ?? 0);
        $total = (int) ($status['total processes'] ?? 0);
        $idle = (int) ($status['idle processes'] ?? 0);
        if ($total <= 0) {
            // A total of 0 is not a real pool reading — nothing to measure.
            return null;
        }
        $listenQueue = (int) ($status['listen queue'] ?? 0);
        $listenQueueLen = (int) ($status['listen queue len'] ?? 0);
        $maxChildrenReached = (int) ($status['max children reached'] ?? 0);
        $slowRequests = (int) ($status['slow requests'] ?? 0);

        $activePct = min(100.0, 100.0 * $active / $total);
        $score = Score::scoreMetric($activePct, self::FPM_ACTIVE_GOOD_PCT, self::FPM_ACTIVE_POOR_PCT);

        return [
            'score' => $score,
            'rating' => Score::getRating($score),
            'activePct' => self::r1($activePct),
            'activeProcesses' => $active,
            'idleProcesses' => $idle,
            'totalProcesses' => $total,
            'listenQueue' => $listenQueue,
            'listenQueueLen' => $listenQueueLen,
            'maxChildrenReached' => $maxChildrenReached,
            'slowRequests' => $slowRequests,
            'caption' => $active . '/' . $total . ' workers busy · '
                . $listenQueue . ' queued · ' . $maxChildrenReached . ' max-children hits',
        ];
    }

    /**
     * jitBufferPressure — how full the OPcache JIT buffer arena is. Source:
     * opcache_get_status(false)['jit'] (buffer_size vs buffer_free). A JIT
     * buffer that fills up stops compiling new hot paths for the remaining life
     * of the process, so nearness to the ceiling is the signal. Gate: OPcache
     * present, JIT actually enabled AND a non-zero buffer. Absent otherwise
     * (JIT off, or a pre-8.0 engine with no JIT) — an honest N/A, never a zero.
     *
     * This is NOT a restatement of opcacheHealth: that axis scores the compiled
     * SCRIPT cache hit rate (a different arena, a different failure mode). No
     * warm-up: proximity to a fixed arena size is honest immediately.
     *
     * @return array<string,mixed>|null
     */
    private static function jitBufferPressure(): ?array
    {
        $jit = self::jitStatus();
        if ($jit === null) {
            return null;
        }
        // JIT must be genuinely on; a present-but-off JIT is nothing to measure.
        $on = !empty($jit['enabled']) && !empty($jit['on']);
        $bufferSize = (int) ($jit['buffer_size'] ?? 0);
        if (!$on || $bufferSize <= 0) {
            return null;
        }
        $bufferFree = (int) ($jit['buffer_free'] ?? 0);
        if ($bufferFree < 0) {
            $bufferFree = 0;
        }
        if ($bufferFree > $bufferSize) {
            $bufferFree = $bufferSize;
        }
        $usedBytes = $bufferSize - $bufferFree;
        $usedPct = min(100.0, 100.0 * $usedBytes / $bufferSize);
        $score = Score::scoreMetric($usedPct, self::JIT_USED_GOOD_PCT, self::JIT_USED_POOR_PCT);
        $usedKb = self::r0($usedBytes / 1024.0);
        $sizeKb = self::r0($bufferSize / 1024.0);

        return [
            'score' => $score,
            'rating' => Score::getRating($score),
            'usedPct' => self::r1($usedPct),
            'usedKb' => $usedKb,
            'bufferKb' => $sizeKb,
            'caption' => self::numStr(self::r1($usedPct)) . '% of the JIT buffer · '
                . $usedKb . '/' . $sizeKb . ' KB',
        ];
    }

    /**
     * includeChurn — is the app still pulling in new files in steady state?
     * Source: count(get_included_files()). Once the app has warmed up
     * ({@see self::INCLUDE_WARMUP_REQUESTS} requests) its include set should be
     * frozen; continued growth means autoload/include churn re-hitting the
     * filesystem on the hot path. Gate: the warm-up baseline has been latched.
     * Reports the high-water mark of new includes since that baseline. Under
     * OPcache-with-preload the number is frozen, so this reports a perfect
     * score — the healthy case, not an absence. Count only — never a file name.
     *
     * @return array<string,mixed>|null
     */
    private static function includeChurn(): ?array
    {
        $s = Store::get(self::INCLUDE_KEY, []);
        if (!is_array($s) || !isset($s['baseline'])) {
            return null; // still warming up — no steady-state baseline yet
        }
        $baseline = (int) $s['baseline'];
        $current = (int) ($s['last'] ?? $baseline);
        $reqs = (int) ($s['reqs'] ?? 0);
        $peakGrowth = (int) ($s['peakGrowth'] ?? 0);
        if ($peakGrowth < 0) {
            $peakGrowth = 0;
        }
        $score = Score::scoreMetric((float) $peakGrowth, self::INCLUDE_GROWTH_GOOD, self::INCLUDE_GROWTH_POOR);

        return [
            'score' => $score,
            'rating' => Score::getRating($score),
            'newIncludes' => $peakGrowth,
            'baselineCount' => $baseline,
            'currentCount' => $current,
            'sampleCount' => $reqs,
            'caption' => $peakGrowth === 0
                ? 'include set frozen · ' . $baseline . ' files'
                : $peakGrowth . ' files added since warm-up · ' . $current . ' total',
        ];
    }

    /**
     * crashFree — the app's crash rate since telemetry start (universal axis,
     * shape ported from Java/Go: {score,rating,crashes,windowMin,caption}).
     * crashes = fatal errors + uncaught exceptions, read from the counts
     * CrashReporter already accrues (its set_exception_handler + fatal shutdown
     * scan) — no second hook. Any crash reports immediately; a zero-crash claim
     * requires >=5 minutes of observation.
     *
     * @return array<string,mixed>|null
     */
    private static function crashFree(): ?array
    {
        $startedAt = Snapshot::startedAtMs();
        $windowMin = (int) ((Telemetry::nowMs() - $startedAt) / 60000);
        if ($windowMin < 0) {
            $windowMin = 0;
        }
        $crashes = CrashReporter::sessionCrashTotal();
        if ($crashes === 0 && $windowMin < self::CRASHFREE_MIN_WINDOW_MIN) {
            return null; // too early to honestly claim crash-free
        }
        $hours = $windowMin / 60.0;
        if ($hours < 1.0 / 60.0) {
            $hours = 1.0 / 60.0;
        }
        $perHour = $crashes / $hours;
        $score = Score::scoreMetric($perHour, self::CRASHFREE_RATE_GOOD, self::CRASHFREE_RATE_POOR);

        return [
            'score' => $score,
            'rating' => Score::getRating($score),
            'crashes' => $crashes,
            'windowMin' => $windowMin,
            'caption' => $crashes > 0
                ? $crashes . ' crashes · ' . $windowMin . 'm'
                : 'crash-free · ' . $windowMin . 'm observed',
        ];
    }

    /**
     * fdSaturation — open file descriptors vs the process's soft "Max open
     * files" limit (shape ported from Java/Go: {score,rating,openFds,maxFds,
     * usedPct,caption}). openFds = entry count of /proc/self/fd; maxFds = the
     * soft limit from /proc/self/limits. Omitted off-Linux, when either file is
     * unreadable, or when the limit is unlimited (no wall to be near). No
     * warm-up: proximity to a hard limit is honest at once.
     *
     * @return array<string,mixed>|null
     */
    private static function fdSaturation(): ?array
    {
        $fd = self::fdStats();
        if ($fd === null) {
            return null;
        }
        [$openFds, $maxFds] = $fd;
        if ($maxFds <= 0 || $openFds < 0) {
            return null;
        }
        $usedPct = self::r1(100.0 * $openFds / $maxFds);
        $score = Score::scoreMetric($usedPct, self::FD_USED_GOOD_PCT, self::FD_USED_POOR_PCT);

        return [
            'score' => $score,
            'rating' => Score::getRating($score),
            'openFds' => $openFds,
            'maxFds' => $maxFds,
            'usedPct' => $usedPct,
            'scopeCode' => 1,
            'caption' => $openFds . ' of ' . $maxFds . ' file descriptors ('
                . self::numStr($usedPct) . '%)',
        ];
    }

    /**
     * reliability — the request error rate (5xx / total) plus throughput
     * (shape ported from Java/Go: {score,rating,total,errors,errorRate,
     * errorPct,rpm,caption}). Reuses the counts AccessPressure already accrues
     * from Instrument::end's recordResponse path — errors = responses with HTTP
     * status >= 500, total = all responses. Gate: >=10 requests.
     *
     * @return array<string,mixed>|null
     */
    private static function reliability(): ?array
    {
        $counts = AccessPressure::responseCounts();
        if ($counts === null) {
            return null;
        }
        [$total, $errors, $spanMs] = $counts;
        if ($total < self::MIN_REQ_FOR_RELIABILITY) {
            return null;
        }
        $errorPct = 100.0 * $errors / $total;
        $score = Score::scoreMetric($errorPct, self::ERR_PCT_GOOD, self::ERR_PCT_POOR);
        $spanMin = $spanMs > 0 ? $spanMs / 60000.0 : 0.0;
        $rpm = $spanMin > 0 ? $total / $spanMin : (float) $total;

        return [
            'score' => $score,
            'rating' => Score::getRating($score),
            'total' => $total,
            'errors' => $errors,
            'errorRate' => round($errorPct / 100.0 * 100) / 100.0,
            'errorPct' => self::r1($errorPct),
            'rpm' => self::r1($rpm),
            'caption' => self::numStr(self::r1($errorPct)) . '% errors · '
                . self::numStr(self::r1($rpm)) . '/min · ' . $total . ' requests',
        ];
    }

    /**
     * The process memory ceiling in bytes, or null when PHP runs without one.
     *
     * `memory_limit` is a real wall: crossing it is a fatal error mid-response,
     * not a slowdown. It is also the one denominator a leak cannot move, which
     * is why the fragmentation reading is taken against it. `-1` (and CLI's
     * common unlimited setting) means there is no wall, and the reading says so
     * rather than inventing one.
     */
    private static function memoryLimitBytes(): ?int
    {
        if (self::$memoryLimitForTests !== null) {
            return self::$memoryLimitForTests > 0 ? self::$memoryLimitForTests : null;
        }
        if (!function_exists('ini_get')) {
            return null;
        }
        $raw = ini_get('memory_limit');
        if (!is_string($raw)) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '' || $raw === '-1' || $raw === '0') {
            return null;
        }
        $value = (float) $raw;
        if ($value <= 0) {
            return null;
        }
        $unit = strtolower(substr($raw, -1));
        if ($unit === 'k') {
            $value *= 1024;
        } elseif ($unit === 'm') {
            $value *= 1024 * 1024;
        } elseif ($unit === 'g') {
            $value *= 1024 * 1024 * 1024;
        }

        return (int) $value;
    }

    /** Test seam for {@see memoryLimitBytes}. Null restores the real read. */
    public static function setMemoryLimitForTests(?int $bytes): void
    {
        self::$memoryLimitForTests = $bytes;
    }

    /**
     * heapFragmentation — allocator-held memory that holds nothing, as a share
     * of this process's memory_limit. Reuses the shared cross-runtime
     * {strandedMb, fragPct, heapMb, maxMb} shape (see the Node and Go kits).
     * Sampled at request end: real = memory_get_usage(true), used =
     * memory_get_usage(false); stranded = real - used. Reports the worst (most
     * stranded) sample. Gate: >=10 samples.
     *
     * The share used to be taken against `real` — the allocator-held total —
     * which is the number that grows when a request retains more. So the
     * reading IMPROVED as memory filled up, and a process holding 300 MB was
     * rated ninety times healthier than an identical 1 MB control. `real` is
     * still reported as `heapMb`, because how much the allocator is holding is
     * a real measurement; it is just not a denominator.
     *
     * @param list<array<string,int|float>> $ring
     * @return array<string,mixed>|null
     */
    private static function heapFragmentation(array $ring): ?array
    {
        $samples = 0;
        $firstAt = null;
        $lastAt = null;
        $worstStranded = -1.0;
        $realAtWorst = 0;
        foreach ($ring as $s) {
            if (!isset($s['realBytes'], $s['usedBytes'])) {
                continue;
            }
            $real = (int) $s['realBytes'];
            $used = (int) $s['usedBytes'];
            if ($real <= 0 || $used < 0) {
                continue;
            }
            $samples++;
            if (isset($s['at'])) {
                $firstAt ??= (int) $s['at'];
                $lastAt = (int) $s['at'];
            }
            $stranded = (float) max(0, $real - $used);
            if ($stranded > $worstStranded) {
                $worstStranded = $stranded;
                $realAtWorst = $real;
            }
        }
        if ($firstAt === null || $lastAt === null
            || $lastAt - $firstAt < self::FRAGMENTATION_MIN_ELAPSED_MS) {
            return null;
        }
        $strandedMb = self::r1($worstStranded / self::BYTES_PER_MB);
        $heapMb = self::r1($realAtWorst / self::BYTES_PER_MB);
        $limitBytes = self::memoryLimitBytes();
        if ($limitBytes === null) {
            // No fixed ceiling means the contract refuses this share.
            return null;
        }
        $fragPct = self::r1(100.0 * $worstStranded / $limitBytes);
        $maxMb = (int) round($limitBytes / self::BYTES_PER_MB);
        $score = Score::scoreMetric($fragPct, self::FRAG_PCT_GOOD, self::FRAG_PCT_POOR);

        return [
            'score' => $score,
            'rating' => Score::getRating($score),
            'fragmentationPct' => $fragPct,
            'strandedMb' => $strandedMb,
            'limitMb' => $maxMb,
            'elapsedMs' => $lastAt - $firstAt,
            'caption' => self::numStr($strandedMb) . ' MB of allocator memory holds nothing · '
                . self::numStr($fragPct) . '% of the ' . $maxMb . ' MB memory limit',
        ];
    }

    /**
     * internedStrings — how full the OPcache interned-strings buffer is. Source:
     * opcache_get_status(false)['interned_strings_usage'] (buffer_size /
     * used_memory). A full interned-strings buffer silently stops new scripts
     * being cached, so high usedPct is bad. Omitted when OPcache is absent or the
     * key is missing (nothing honest to measure).
     *
     * @return array<string,mixed>|null
     */
    private static function internedStrings(): ?array
    {
        $status = self::opcacheStatus();
        if ($status === null) {
            return null;
        }
        $iu = $status['interned_strings_usage'] ?? null;
        if (!is_array($iu)) {
            return null;
        }
        $bufferBytes = (int) ($iu['buffer_size'] ?? 0);
        if ($bufferBytes <= 0) {
            return null;
        }
        $usedBytes = (int) ($iu['used_memory'] ?? 0);
        if ($usedBytes < 0) {
            $usedBytes = 0;
        }
        if ($usedBytes > $bufferBytes) {
            $usedBytes = $bufferBytes;
        }
        $usedPct = self::r1(100.0 * $usedBytes / $bufferBytes);
        $usedKb = self::r0($usedBytes / 1024.0);
        $bufferKb = self::r0($bufferBytes / 1024.0);
        $score = Score::scoreMetric(
            100.0 * $usedBytes / $bufferBytes,
            self::INTERNED_USED_GOOD_PCT,
            self::INTERNED_USED_POOR_PCT
        );

        return [
            'score' => $score,
            'rating' => Score::getRating($score),
            'usedPct' => $usedPct,
            'usedKb' => $usedKb,
            'bufferKb' => $bufferKb,
            'caption' => self::numStr($usedPct) . '% of the interned-strings buffer · '
                . $usedKb . '/' . $bufferKb . ' KB',
        ];
    }

    /**
     * bootCost — how long the framework takes to boot before the app does any
     * work, per request: from REQUEST_TIME_FLOAT to the moment the kit
     * initialises. Reports the p75 and worst boot over the sampled series. Gate:
     * >=10 samples. Absent under CLI (no request clock); absurd samples were
     * already dropped at sample time.
     *
     * @return array<string,mixed>|null
     */
    private static function bootCost(): ?array
    {
        $durs = self::durations(self::BOOT_KEY);
        if (count($durs) < self::MIN_BOOT_SAMPLES) {
            return null;
        }
        sort($durs);
        $p75 = self::percentile($durs, 0.75);
        $worst = $durs[count($durs) - 1];
        $score = Score::scoreMetric($p75, self::BOOT_MS_GOOD, self::BOOT_MS_POOR);

        return [
            'score' => $score,
            'rating' => Score::getRating($score),
            'p75Ms' => self::r1($p75),
            'worstMs' => self::r1($worst),
            'sampleCount' => count($durs),
            'caption' => self::numStr(self::r1($p75)) . ' ms framework boot p75 · worst '
                . self::numStr(self::r1($worst)) . ' ms',
        ];
    }

    /**
     * queueTime — how long a request waited before PHP received it, derived from
     * the proxy's X-Request-Start / X-Queue-Start header. Reports the p75 and
     * worst wait over the sampled series. Only the DERIVED duration is ever
     * stored — never the header text. Gate: >=10 samples; absent forever when the
     * header never appears.
     *
     * @return array<string,mixed>|null
     */
    private static function queueTime(): ?array
    {
        $durs = self::durations(self::QUEUE_KEY);
        if (count($durs) < self::MIN_QUEUE_SAMPLES) {
            return null;
        }
        sort($durs);
        $p75 = self::percentile($durs, 0.75);
        $worst = $durs[count($durs) - 1];
        $score = Score::scoreMetric($p75, self::QUEUE_MS_GOOD, self::QUEUE_MS_POOR);

        return [
            'score' => $score,
            'rating' => Score::getRating($score),
            'p75Ms' => self::r1($p75),
            'worstMs' => self::r1($worst),
            'sampleCount' => count($durs),
            'caption' => self::numStr(self::r1($p75)) . ' ms queued before PHP p75 · worst '
                . self::numStr(self::r1($worst)) . ' ms',
        ];
    }

    /**
     * preloadCoverage — how much of the app is served from the OPcache preload.
     * Source: opcache_get_status(false)['preload_statistics'] — the scripts
     * array is COUNTED (never read, never kept), and memory_consumption gives
     * usedMb. Higher coverage is better, so it is scored inverted against the
     * un-preloaded share. Omitted when preloading is not configured.
     *
     * @return array<string,mixed>|null
     */
    private static function preloadCoverage(): ?array
    {
        $status = self::opcacheStatus();
        if ($status === null) {
            return null;
        }
        $pre = $status['preload_statistics'] ?? null;
        if (!is_array($pre)) {
            return null;
        }
        $scripts = $pre['scripts'] ?? null;
        if (!is_array($scripts)) {
            return null;
        }
        $preloaded = count($scripts);
        $stats = is_array($status['opcache_statistics'] ?? null) ? $status['opcache_statistics'] : [];
        $cached = (int) ($stats['num_cached_scripts'] ?? 0);
        // The cached count includes preloaded scripts; guard against a smaller
        // cached figure so the share is never negative or over 100%.
        if ($cached < $preloaded) {
            $cached = $preloaded;
        }
        if ($cached <= 0) {
            return null;
        }
        $preloadedPct = self::r1(100.0 * $preloaded / $cached);
        $uncoveredPct = 100.0 - (100.0 * $preloaded / $cached);
        $usedMb = self::r1(((int) ($pre['memory_consumption'] ?? 0)) / self::BYTES_PER_MB);
        $score = Score::scoreMetric(
            $uncoveredPct,
            self::PRELOAD_UNCOVERED_GOOD_PCT,
            self::PRELOAD_UNCOVERED_POOR_PCT
        );

        return [
            'score' => $score,
            'rating' => Score::getRating($score),
            'preloadedScripts' => $preloaded,
            'cachedScripts' => $cached,
            'preloadedPct' => $preloadedPct,
            'usedMb' => $usedMb,
            'caption' => $preloaded . ' of ' . $cached . ' scripts preloaded ('
                . self::numStr($preloadedPct) . '%) · ' . self::numStr($usedMb) . ' MB',
        ];
    }

    // ── Live-source readers (test-seam aware) ────────────────────────────────

    /**
     * The current PHP-FPM pool status, or null when this is not an FPM worker
     * with a status path (the function is absent). Honours the test override.
     *
     * @return array<string,int|float>|null
     */
    private static function fpmStatus(): ?array
    {
        if (self::$fpmStatusOverridden) {
            return self::$fpmStatusOverride;
        }
        if (!function_exists('fpm_get_status')) {
            return null;
        }
        $status = @fpm_get_status();

        return is_array($status) ? $status : null;
    }

    /**
     * The OPcache JIT sub-status, or null when OPcache/JIT is unavailable.
     * Honours the test override.
     *
     * @return array<string,int|float>|null
     */
    private static function jitStatus(): ?array
    {
        if (self::$jitStatusOverridden) {
            return self::$jitStatusOverride;
        }
        if (!function_exists('opcache_get_status')) {
            return null;
        }
        $status = @opcache_get_status(false);
        if (!is_array($status) || empty($status['opcache_enabled'])) {
            return null;
        }
        $jit = $status['jit'] ?? null;

        return is_array($jit) ? $jit : null;
    }

    /**
     * How many files this request has included/required. Count only. Honours
     * the test override; null when the function is unavailable.
     */
    private static function includedFileCount(): ?int
    {
        if (self::$includeCountOverride !== null) {
            return self::$includeCountOverride;
        }
        if (!function_exists('get_included_files')) {
            return null;
        }

        return count(get_included_files());
    }

    /**
     * The framework boot elapsed (ms): from $_SERVER['REQUEST_TIME_FLOAT'] to
     * now (the moment the kit samples). null under CLI (no request clock) so
     * bootCost never gates there. Honours the test override.
     */
    private static function bootElapsedMs(): ?float
    {
        if (self::$bootMsOverride !== null) {
            return self::$bootMsOverride;
        }
        $startFloat = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
        if (!is_numeric($startFloat)) {
            return null;
        }

        return (microtime(true) - (float) $startFloat) * 1000.0;
    }

    /**
     * The queue wait (ms): now minus the epoch stamped by the proxy in
     * X-Request-Start / X-Queue-Start. Accepts the nginx "t=<epoch>" form, bare
     * seconds, or milliseconds — the magnitude of the parsed number decides the
     * unit. null when neither header is present (queueTime then stays absent
     * forever). The header text is parsed and DISCARDED here; only the derived
     * duration is ever returned. Honours the test override.
     */
    private static function queueWaitMs(): ?float
    {
        if (self::$queueMsOverride !== null) {
            return self::$queueMsOverride;
        }
        $raw = $_SERVER['HTTP_X_REQUEST_START'] ?? ($_SERVER['HTTP_X_QUEUE_START'] ?? null);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        // Strip the optional nginx "t=" prefix, then take the leading number.
        $s = $raw;
        if (strncmp($s, 't=', 2) === 0) {
            $s = substr($s, 2);
        }
        if (!preg_match('/[0-9]+(?:\.[0-9]+)?/', $s, $m)) {
            return null;
        }
        $startVal = (float) $m[0];
        if ($startVal <= 0) {
            return null;
        }
        $nowMs = microtime(true) * 1000.0;
        // A value that looks like seconds (roughly < year-2001 in ms terms) is
        // scaled to ms; anything larger is already ms.
        $startMs = $startVal < 1_000_000_000_000.0 ? $startVal * 1000.0 : $startVal;

        return $nowMs - $startMs;
    }

    /**
     * Open file descriptors + soft "Max open files" limit as [openFds, maxFds],
     * or null off-Linux / when either /proc file is unreadable / when the limit
     * is unlimited. Counts only — never a descriptor target or a path. Honours
     * the test override.
     *
     * @return array{0:int,1:int}|null
     */
    private static function fdStats(): ?array
    {
        if (self::$fdStatsOverridden) {
            return self::$fdStatsOverride;
        }
        $entries = @scandir('/proc/self/fd');
        if ($entries === false) {
            return null;
        }
        // Drop "." and ".." — count only real descriptor entries.
        $openFds = 0;
        foreach ($entries as $e) {
            if ($e !== '.' && $e !== '..') {
                $openFds++;
            }
        }
        $limits = @file_get_contents('/proc/self/limits');
        if ($limits === false || $limits === '') {
            return null;
        }
        if (!preg_match('/^Max open files\s+(\S+)\s+(\S+)/m', $limits, $m)) {
            return null;
        }
        $soft = $m[1];
        if (strcasecmp($soft, 'unlimited') === 0 || !ctype_digit($soft)) {
            return null;
        }
        $maxFds = (int) $soft;
        if ($maxFds <= 0) {
            return null;
        }

        return [$openFds, $maxFds];
    }

    /**
     * The full opcache_get_status(false) struct, or null when OPcache is absent
     * or disabled at runtime. Honours the test override so internedStrings and
     * preloadCoverage are drivable inside a CLI runner.
     *
     * @return array<string,mixed>|null
     */
    private static function opcacheStatus(): ?array
    {
        if (self::$opcacheStatusOverridden) {
            return self::$opcacheStatusOverride;
        }
        if (!function_exists('opcache_get_status')) {
            return null;
        }
        $status = @opcache_get_status(false);
        if (!is_array($status) || empty($status['opcache_enabled'])) {
            return null;
        }

        return $status;
    }

    /**
     * The stored duration series (ms) under $key, oldest-first.
     *
     * @return list<float>
     */
    private static function durations(string $key): array
    {
        $ring = Store::get($key, []);
        if (!is_array($ring)) {
            return [];
        }
        $out = [];
        foreach ($ring as $s) {
            if (is_array($s) && isset($s['ms'])) {
                $out[] = (float) $s['ms'];
            }
        }

        return $out;
    }

    // ── Test seams ────────────────────────────────────────────────────────

    /**
     * Drive fpmWorkerSaturation from a synthetic pool status (pass null to
     * simulate a present-but-empty read; use {@see self::resetFpmStatusForTests}
     * to fall back to the real fpm_get_status()).
     *
     * @param array<string,int|float>|null $status
     */
    public static function setFpmStatusForTests(?array $status): void
    {
        self::$fpmStatusOverride = $status;
        self::$fpmStatusOverridden = true;
    }

    public static function resetFpmStatusForTests(): void
    {
        self::$fpmStatusOverride = null;
        self::$fpmStatusOverridden = false;
    }

    /**
     * Drive jitBufferPressure from a synthetic opcache jit sub-status.
     *
     * @param array<string,int|float>|null $jit
     */
    public static function setJitStatusForTests(?array $jit): void
    {
        self::$jitStatusOverride = $jit;
        self::$jitStatusOverridden = true;
    }

    public static function resetJitStatusForTests(): void
    {
        self::$jitStatusOverride = null;
        self::$jitStatusOverridden = false;
    }

    /** Drive includeChurn from a synthetic included-file count. */
    public static function setIncludeCountForTests(?int $count): void
    {
        self::$includeCountOverride = $count;
    }

    public static function resetIncludeCountForTests(): void
    {
        self::$includeCountOverride = null;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Parse a PHP ini shorthand size ("128M", "4096K", "1G", "-1") to bytes.
     * Returns null for unreadable input; -1 (unlimited) is passed through as -1
     * so callers can distinguish "no limit" from "unknown".
     */
    private static function iniBytes(mixed $raw): ?int
    {
        if ($raw === false || $raw === null || $raw === '') {
            return null;
        }
        $s = trim((string) $raw);
        if ($s === '-1') {
            return -1;
        }
        if (!preg_match('/^(\d+)\s*([KMG]?)$/i', $s, $m)) {
            return ctype_digit($s) ? (int) $s : null;
        }
        $n = (int) $m[1];
        switch (strtoupper($m[2])) {
            case 'G':
                return $n * 1024 * 1024 * 1024;
            case 'M':
                return $n * 1024 * 1024;
            case 'K':
                return $n * 1024;
            default:
                return $n;
        }
    }

    /**
     * The p-th percentile of a pre-sorted ascending list (nearest-rank).
     *
     * @param list<float> $sorted
     */
    private static function percentile(array $sorted, float $p): float
    {
        $n = count($sorted);
        if ($n === 0) {
            return 0.0;
        }
        $rank = (int) ceil($p * $n) - 1;
        if ($rank < 0) {
            $rank = 0;
        }
        if ($rank >= $n) {
            $rank = $n - 1;
        }

        return $sorted[$rank];
    }

    private static function r0(float $n): int
    {
        return (int) round($n);
    }

    private static function r1(float $n): float
    {
        return round($n * 10) / 10.0;
    }

    /** One decimal place, or three significant figures when that is finer. */
    private static function shareRound(float $n): float
    {
        if ($n == 0.0) {
            return 0.0;
        }
        $decimals = 3 - (int) floor(log10(abs($n))) - 1;
        $decimals = max(1, min(6, $decimals));

        return round($n, $decimals);
    }

    /** JS-style number stringify: "2" not "2.0", "0.5" not "0.50". */
    private static function numStr(float $n): string
    {
        if ($n == floor($n) && is_finite($n)) {
            return (string) (int) $n;
        }
        $s = (string) $n;
        if (strpos($s, '.') !== false) {
            $s = rtrim(rtrim($s, '0'), '.');
        }

        return $s;
    }

    /**
     * The current stored per-request sample ring, oldest-first.
     *
     * @return list<array<string,int|float>>
     */
    private static function ring(): array
    {
        $ring = Store::get(self::MEM_KEY, []);

        return is_array($ring) ? array_values($ring) : [];
    }
}
