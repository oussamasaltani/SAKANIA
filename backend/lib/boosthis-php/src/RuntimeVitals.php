<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * Runtime-vitals axes (PHP) — a display-only, additive port of the Java and Go
 * runtime-vitals meters, keeping ONLY the axes PHP can honestly measure.
 *
 * WHY THIS FILE LOOKS DIFFERENT FROM THE JVM/Go SIBLINGS.
 * The other runtimes have a long-lived process that samples itself on a request
 * boundary and keeps a rolling ring in memory. PHP has no such process: under
 * PHP-FPM or mod_php every request is born, serves one request, and dies. There
 * is nowhere in-process to accumulate a trend. So EVERY sample this meter needs
 * is written to Store (the cross-request backend) once per request via
 * {@see self::sample()}, and each axis reads that ring back at snapshot time.
 * The gates, keys, value fields and rating bands are otherwise identical to the
 * siblings so the dashboard renders one shared set of gauges.
 *
 * HONESTY. An axis is either genuinely measured or ABSENT from the axes map.
 * Each axis self-gates on its own evidence floor (enough samples, enough span,
 * a readable source) and is simply missing until that floor is met. Nothing
 * here is ever a fabricated 0 or 100.
 *
 * ADDITIVE. Every axis is display-only. NOTHING here feeds the composite Speed
 * score (responsiveness only, computed in Snapshot::buildAxes).
 *
 * GUEST SAFETY. Every public body runs through Safe, filesystem reads are
 * suppressed with @, and any failure degrades to an absent axis — never an
 * exception, output, header or measurable slowdown in the host request.
 *
 * Ported from lib/boosthis-java/src/app/boosthis/{RuntimeVitals,ExtraMeters}.java
 * and lib/boosthis-go/runtime_vitals.go.
 *
 * DELIBERATELY NOT PORTED — JVM/Go concepts with no honest PHP equivalent:
 *   - liveSetGrowth       (retained old-gen after GC; PHP's Zend allocator is
 *                          arena/refcount based, there is no old generation to
 *                          measure a live-set slope against).
 *   - forcedGc            (explicit System.gc()-style full collections; PHP's
 *                          gc_collect_cycles is a cycle collector, not a full
 *                          heap GC, and forced collections are not a meaningful
 *                          reliability signal here — gcCycles in ExtraMeters
 *                          reports the honest PHP equivalent instead).
 *   - mappedBufferGrowth  (JVM mapped/direct NIO buffer pools; PHP exposes no
 *                          such pool, and mmap growth is invisible to userland).
 *   - monitorContention   (JVM monitor/lock contention; PHP's shared-nothing
 *                          per-request model has no in-process locks to contend
 *                          on — the workers do not share a heap).
 */
final class RuntimeVitals
{
    private function __construct()
    {
    }

    // ── processCpuLoad bands (parity with Java CPU_LOAD_GOOD/POOR) ───────────
    // Share of its own available CPU this process is actually burning (%).
    // <=65% is comfortable (100); >=95% means it is CPU-bound (0).
    private const CPU_LOAD_GOOD_PCT = 65.0;
    private const CPU_LOAD_POOR_PCT = 95.0;
    // At least this many samples, spanning this much wall clock, before an
    // average CPU share means anything. PHP-specific note: a single request's
    // rusage delta over a few ms is pure noise, so the span floor matters even
    // more here than on the JVM.
    private const MIN_CPU_SAMPLES = 6;
    private const MIN_CPU_SPAN_MS = 60_000;

    // ── systemLoad bands (run-queue depth per CPU; parity with Java) ─────────
    // <=0.7 runnable tasks per CPU is a calm host (100); >=2.0 means the host is
    // oversubscribed and this process waits for CPU it was promised (0).
    private const SYS_LOAD_GOOD = 0.7;
    private const SYS_LOAD_POOR = 2.0;
    // The kernel's load average is a 1-minute figure — don't trust it before a
    // minute of observation has elapsed.
    private const MIN_SYS_LOAD_UPTIME_MS = 60_000;

    // ── residentGrowth bands (whole-process RSS slope, MB/min; parity Java) ──
    private const RSS_GROWTH_GOOD = 0.5;
    private const RSS_GROWTH_POOR = 5.0;
    private const MIN_RSS_SAMPLES = 12;
    private const MIN_RSS_SPAN_MS = 300_000;
    private const TREND_STARTUP_EXCLUSION_MS = 60_000;
    private const TREND_BUCKET_MS = 30_000;
    private const RESIDENT_RISE_FLOOR_MB = 30.0;
    private const MEMORY_STABILITY_GOOD_MB_MIN = 2.0;
    private const MEMORY_STABILITY_POOR_MB_MIN = 25.0;
    private const MEMORY_STABILITY_RISE_FLOOR_MB = 30.0;

    // ── containerPressure bands (parity with Java/Go/Node; see the memory) ──
    // Process/cgroup memory vs the cgroup memory LIMIT (the container OOM-kill
    // wall). <=75% is comfortable headroom (100); >=95% is imminent-OOM (0). A
    // harder wall than any heap soft cap — RSS, native allocations and stacks
    // all count and nothing GCs it back — so the bands sit higher (75/95).
    // NO WARM-UP: proximity to a hard limit is honest the instant the process
    // starts, so this axis needs no sample ring at all.
    private const CONTAINER_PCT_GOOD = 75.0;
    private const CONTAINER_PCT_POOR = 95.0;
    // Near-Int64-max sentinel; a cgroup-v1 limit at/above it means no real cap
    // is set, so the axis is omitted rather than faked.
    private const CGROUP_V1_UNLIMITED = 0x7000_0000_0000_0000;

    // One process-vitals sample per request is thrown away if it lands within
    // this window of the previous one, so a burst of requests cannot flood the
    // ring or make the CPU delta span a sub-millisecond interval.
    private const SAMPLE_THROTTLE_MS = 1_000;
    // Bounded ring: a long-lived, high-traffic install can never grow the stored
    // sample list without limit. 300s / 1s of RSS history fits comfortably.
    private const RING_CAP = 360;

    private const BYTES_PER_MB = 1048576.0;
    private const STORE_KEY = 'vitals:samples';

    /**
     * Record ONE process-vitals sample into Store. Called once per request (the
     * PHP analogue of the JVM/Go request-boundary sampler). Guest-safe: no-op
     * under the kill-switch, never throws, throttled so a burst is cheap.
     *
     * Each sample is the cumulative-since-process-start rusage plus the current
     * RSS, timestamped. processCpuLoad differences consecutive samples to get a
     * per-interval CPU share; residentGrowth fits a slope over the RSS series.
     */
    public static function sample(): void
    {
        if (RuntimeFlags::disabled()) {
            return;
        }
        Safe::fire(static function (): void {
            $now = Telemetry::nowMs();
            $ru = @getrusage();
            // rusage user+system time in microseconds, cumulative since this
            // worker started. On a non-POSIX host getrusage can be false/empty;
            // then the CPU axis simply never gates (honest absence).
            $cpuUs = null;
            if (is_array($ru) && isset(
                $ru['ru_utime.tv_sec'],
                $ru['ru_utime.tv_usec'],
                $ru['ru_stime.tv_sec'],
                $ru['ru_stime.tv_usec']
            )) {
                $cpuUs = ((int) $ru['ru_utime.tv_sec'] + (int) $ru['ru_stime.tv_sec']) * 1_000_000
                    + (int) $ru['ru_utime.tv_usec'] + (int) $ru['ru_stime.tv_usec'];
            }
            // Whole-process RSS in bytes from the kernel's own view. Omitted
            // permanently where /proc/self/statm does not exist (non-Linux or a
            // restricted sandbox) — that absence is honest, not a zero.
            $rssBytes = self::readRssBytes();
            // PHP has no old generation, but the request-end used arena is the
            // live set this cross-request kit can honestly observe.
            $liveBytes = function_exists('memory_get_usage')
                ? (int) memory_get_usage(false)
                : null;

            Store::update(self::STORE_KEY, static function ($cur) use ($now, $cpuUs, $rssBytes, $liveBytes) {
                $ring = is_array($cur) ? $cur : [];
                $last = $ring[count($ring) - 1] ?? null;
                if (is_array($last) && isset($last['at'])
                    && $now - (int) $last['at'] < self::SAMPLE_THROTTLE_MS) {
                    // Throttled: keep the ring untouched.
                    return $ring;
                }
                $entry = ['at' => $now];
                if ($cpuUs !== null) {
                    $entry['cpuUs'] = $cpuUs;
                }
                if ($rssBytes !== null) {
                    $entry['rssBytes'] = $rssBytes;
                }
                if ($liveBytes !== null) {
                    $entry['liveBytes'] = $liveBytes;
                }
                $ring[] = $entry;
                while (count($ring) > self::RING_CAP) {
                    array_shift($ring);
                }

                return array_values($ring);
            }, []);
        });
    }

    /**
     * Attach every currently-available runtime-vitals axis to $axes. A
     * warming-up or unmeasurable axis is simply omitted (the server renders an
     * absent axis as pending). Guest-safe: never throws.
     *
     * @param array<string,mixed> $axes
     */
    public static function addVitals(array &$axes): void
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
            $add($axes, 'processCpuLoad', self::processCpuLoad($ring));
            $add($axes, 'systemLoad', self::systemLoad($ring));
            $add($axes, 'residentGrowth', self::residentGrowth($ring));
            $add($axes, 'memoryStability', self::memoryStability($ring));
            $add($axes, 'containerPressure', self::containerPressure());
            // patchLag rides here for the same reason it does in the Java and
            // Go kits: it is a vitals-style read of the SERVING build, not a
            // rolling meter. Absent when the build time cannot be known.
            $add($axes, 'patchLag', BuildIdentity::readPatchLag());
        });
    }

    // ── Axes ────────────────────────────────────────────────────────────────

    /**
     * processCpuLoad — the share of its own available CPU this process is
     * burning, averaged over the sampled window, with the worst interval
     * alongside. This is the process's own appetite, a different question from
     * containerPressure (memory) or systemLoad (other tenants competing).
     *
     * PHP-SPECIFIC SOURCE. The JVM reads an instantaneous getProcessCpuLoad()
     * fraction; PHP has no such bean, so each interval's CPU share is derived
     * from the rusage delta between two stored samples over their wall-clock
     * delta: pct = 100 * (cpuUs_delta / 1000) / wall_ms. This measures the CPU
     * the workers genuinely consumed between sample points, normalized to real
     * time. Absent until >=6 samples span >=60s.
     *
     * @param list<array<string,int>> $ring
     * @return array<string,mixed>|null
     */
    private static function processCpuLoad(array $ring): ?array
    {
        // Keep only samples that actually carry a CPU reading (getrusage may
        // have been unavailable on some hosts) and preserve order.
        $cpu = [];
        foreach ($ring as $s) {
            if (isset($s['cpuUs'], $s['at'])) {
                $cpu[] = $s;
            }
        }
        if (count($cpu) < self::MIN_CPU_SAMPLES) {
            return null;
        }
        $first = $cpu[0];
        $last = $cpu[count($cpu) - 1];
        if ((int) $last['at'] - (int) $first['at'] < self::MIN_CPU_SPAN_MS) {
            return null;
        }
        // Per-interval CPU%: honest deltas between consecutive samples.
        $pcts = [];
        for ($i = 1; $i < count($cpu); $i++) {
            $wallMs = (int) $cpu[$i]['at'] - (int) $cpu[$i - 1]['at'];
            $cpuDeltaUs = (int) $cpu[$i]['cpuUs'] - (int) $cpu[$i - 1]['cpuUs'];
            if ($wallMs <= 0 || $cpuDeltaUs < 0) {
                // A worker restart resets cumulative rusage to a smaller value;
                // a negative delta is meaningless, so skip that interval rather
                // than report a bogus number.
                continue;
            }
            // cpuDeltaUs / 1000 = cpu-ms consumed; over wallMs of wall time.
            $pcts[] = 100.0 * ($cpuDeltaUs / 1000.0) / $wallMs;
        }
        if (count($pcts) < self::MIN_CPU_SAMPLES - 1) {
            return null;
        }
        $sum = 0.0;
        $peak = 0.0;
        foreach ($pcts as $p) {
            $sum += $p;
            if ($p > $peak) {
                $peak = $p;
            }
        }
        $avg = $sum / count($pcts);
        $score = Score::scoreMetric($avg, self::CPU_LOAD_GOOD_PCT, self::CPU_LOAD_POOR_PCT);
        $axis = [
            'score' => $score,
            'rating' => Score::getRating($score),
            'cpuPct' => self::r1($avg),
            'peakPct' => self::r1($peak),
            'sampleCount' => count($cpu),
            'caption' => self::numStr(self::r1($avg)) . '% of its CPU used · peak '
                . self::numStr(self::r1($peak)) . '%',
        ];
        $cores = self::cpuCount();
        if ($cores !== null) {
            $axis['cores'] = $cores;
            $axis['caption'] .= ' · ' . (100 * $cores) . '% ceiling across ' . $cores . ' cores';
        }

        return $axis;
    }

    /**
     * systemLoad — the host's run-queue depth per CPU. Tells a developer the
     * machine is oversubscribed even when this process is neither throttled nor
     * busy: work is waiting because someone else has the CPU. Absent before 60s
     * of observation and on platforms that report no load average.
     *
     * @param list<array<string,int>> $ring
     * @return array<string,mixed>|null
     */
    private static function systemLoad(array $ring): ?array
    {
        // Uptime gate: the load average is a 1-minute figure, so require a
        // minute of observation (the span of our own sample ring is the honest
        // stand-in for "how long we've been watching this process").
        if ($ring === []) {
            return null;
        }
        $first = $ring[0];
        $last = $ring[count($ring) - 1];
        if ((int) $last['at'] - (int) $first['at'] < self::MIN_SYS_LOAD_UPTIME_MS) {
            return null;
        }
        if (!function_exists('sys_getloadavg')) {
            return null;
        }
        $loads = @sys_getloadavg();
        if (!is_array($loads) || !isset($loads[0])) {
            return null;
        }
        $load = (float) $loads[0];
        if ($load < 0) {
            // Windows / unsupported platforms return a negative — omit, never
            // fabricate.
            return null;
        }
        $cpus = self::cpuCount();
        if ($cpus === null || $cpus < 1) {
            return null;
        }
        $perCpu = $load / $cpus;
        $score = Score::scoreMetric($perCpu, self::SYS_LOAD_GOOD, self::SYS_LOAD_POOR);

        return [
            'score' => $score,
            'rating' => Score::getRating($score),
            'loadPerCpu' => self::r2($perCpu),
            'load' => self::r2($load),
            'cpus' => $cpus,
            'scopeCode' => 3,
            'caption' => self::numStr(self::r2($perCpu)) . ' runnable per CPU · ' . $cpus . ' CPUs',
        ];
    }

    /**
     * residentGrowth — the slope of the WHOLE process's resident memory
     * (MB/min) as the kernel sees it. Heap/allocator meters cannot see native
     * memory — extension buffers, mmapped files, stacks — so a process can look
     * heap-stable while marching toward an OOM kill. Absent until >=12 samples
     * span >=300s, and permanently absent where /proc/self/statm is unreadable.
     *
     * @param list<array<string,int>> $ring
     * @return array<string,mixed>|null
     */
    private static function residentGrowth(array $ring): ?array
    {
        $rss = [];
        foreach ($ring as $s) {
            if (isset($s['rssBytes'], $s['at'])) {
                $rss[] = ['at' => (int) $s['at'], 'mb' => (int) $s['rssBytes'] / self::BYTES_PER_MB];
            }
        }
        if (count($rss) < self::MIN_RSS_SAMPLES) {
            return null;
        }
        $first = $rss[0];
        $last = $rss[count($rss) - 1];
        if ($last['at'] - $first['at'] < self::MIN_RSS_SPAN_MS) {
            return null;
        }
        return self::trendAxis(
            $rss,
            self::RSS_GROWTH_GOOD,
            self::RSS_GROWTH_POOR,
            self::RESIDENT_RISE_FLOOR_MB,
            true
        );
    }

    /** Live request-end arena growth, persisted across requests in Store. */
    private static function memoryStability(array $ring): ?array
    {
        $live = [];
        foreach ($ring as $s) {
            if (isset($s['liveBytes'], $s['at'])) {
                $live[] = ['at' => (int) $s['at'], 'mb' => (int) $s['liveBytes'] / self::BYTES_PER_MB];
            }
        }
        return self::trendAxis(
            $live,
            self::MEMORY_STABILITY_GOOD_MB_MIN,
            self::MEMORY_STABILITY_POOR_MB_MIN,
            self::MEMORY_STABILITY_RISE_FLOOR_MB,
            false
        );
    }

    /**
     * Contract trend: discard startup, fit bucket low-water marks, publish the
     * slope SE, and abstain when zero lies inside one standard error.
     */
    private static function trendAxis(
        array $points,
        float $good,
        float $poor,
        float $riseFloor,
        bool $includeRss
    ): ?array {
        if ($points === []) {
            return null;
        }
        $cutoff = (int) $points[0]['at'] + self::TREND_STARTUP_EXCLUSION_MS;
        $buckets = [];
        foreach ($points as $p) {
            if ((int) $p['at'] < $cutoff) {
                continue;
            }
            $bucket = intdiv((int) $p['at'] - $cutoff, self::TREND_BUCKET_MS);
            if (!isset($buckets[$bucket]) || (float) $p['mb'] < $buckets[$bucket]['mb']) {
                $buckets[$bucket] = $p;
            }
        }
        $judged = array_values($buckets);
        if (count($judged) < self::MIN_RSS_SAMPLES) {
            return null;
        }
        $spanMs = (int) $judged[count($judged) - 1]['at'] - (int) $judged[0]['at'];
        if ($spanMs < self::MIN_RSS_SPAN_MS) {
            return null;
        }
        [$growth, $se] = self::slopeAndSePerMin($judged);
        $axis = [
            'growthMbPerMin' => self::r1($growth),
            'slopeSeMbPerMin' => self::r1($se),
            'sampleCount' => count($judged),
            'windowMin' => self::r1($spanMs / 60_000.0),
        ];
        if ($includeRss) {
            $axis['rssMb'] = self::r0((float) $judged[count($judged) - 1]['mb']);
        }
        if (abs($growth) <= $se) {
            $axis['rating'] = 'not-scored';
            return $axis;
        }
        $rise = $growth * $spanMs / 60_000.0;
        $score = $rise < $riseFloor
            ? 100
            : Score::scoreMetric(max(0.0, $growth), $good, $poor);
        $axis['score'] = $score;
        $axis['rating'] = Score::getRating($score);
        return $axis;
    }

    /**
     * containerPressure — process/cgroup memory in use vs the cgroup memory
     * LIMIT (the container OOM-kill wall). Reader is cgroup v2 first, then v1,
     * else the axis is OMITTED (never fabricated). Omitted entirely outside a
     * container. NO warm-up: proximity to a hard limit is honest immediately.
     * Reuses heapHeadroom's already-allowlisted usedMb/maxMb/usedPct field
     * shape, matching the cross-runtime contract (see
     * .agents/memory/boosthis-container-pressure.md).
     *
     * @return array<string,mixed>|null
     */
    private static function containerPressure(): ?array
    {
        $mem = self::readCgroupMem();
        if ($mem === null) {
            return null;
        }
        [$usedBytes, $maxBytes] = $mem;
        if ($maxBytes <= 0 || $usedBytes < 0) {
            return null;
        }
        $usedPct = 100.0 * $usedBytes / $maxBytes;
        $score = Score::scoreMetric($usedPct, self::CONTAINER_PCT_GOOD, self::CONTAINER_PCT_POOR);
        $usedMb = self::r0($usedBytes / self::BYTES_PER_MB);
        $maxMb = self::r0($maxBytes / self::BYTES_PER_MB);

        return [
            'score' => $score,
            'rating' => Score::getRating($score),
            'usedMb' => $usedMb,
            'maxMb' => $maxMb,
            'usedPct' => self::r1($usedPct),
            'caption' => $usedMb . '/' . $maxMb . ' MB container limit · '
                . self::numStr(self::r1($usedPct)) . '% used',
        ];
    }

    // ── Sources ───────────────────────────────────────────────────────────────

    /**
     * Whole-process resident set size in bytes from /proc/self/statm, or null
     * where that view does not exist. statm fields are in PAGES:
     * "size resident shared text lib data dt" — the second is RSS.
     */
    private static function readRssBytes(): ?int
    {
        $raw = @file_get_contents('/proc/self/statm');
        if ($raw === false || $raw === '') {
            return null;
        }
        $parts = preg_split('/\s+/', trim($raw)) ?: [];
        if (!isset($parts[1]) || !ctype_digit($parts[1])) {
            return null;
        }
        $pages = (int) $parts[1];
        if ($pages <= 0) {
            return null;
        }
        // Page size: getpagesize() is 4096 on the platforms with /proc anyway;
        // it is the honest multiplier for statm's page counts.
        $pageSize = function_exists('getpagesize') ? (int) getpagesize() : 4096;
        if ($pageSize <= 0) {
            $pageSize = 4096;
        }

        return $pages * $pageSize;
    }

    /**
     * Read the current cgroup memory usage + limit as [usedBytes, maxBytes], or
     * null when no real cap is set / any read fails. cgroup v2 (memory.current /
     * memory.max) first, then the v1 fallback (memory.usage_in_bytes /
     * memory.limit_in_bytes). "max" or a near-Int64-max sentinel means unlimited
     * -> null, so the axis is omitted rather than faked. Never shells out.
     *
     * @return array{0:int,1:int}|null
     */
    private static function readCgroupMem(): ?array
    {
        // cgroup v2 — the unified hierarchy.
        $rawMax = @file_get_contents('/sys/fs/cgroup/memory.max');
        if ($rawMax !== false) {
            $maxStr = trim($rawMax);
            if ($maxStr !== 'max' && ctype_digit($maxStr)) {
                $maxV = (int) $maxStr;
                if ($maxV > 0 && $maxV < self::CGROUP_V1_UNLIMITED) {
                    $rawCur = @file_get_contents('/sys/fs/cgroup/memory.current');
                    if ($rawCur !== false) {
                        $curStr = trim($rawCur);
                        if (ctype_digit($curStr)) {
                            return [(int) $curStr, $maxV];
                        }
                    }
                }
            }
        }
        // cgroup v1 — the legacy per-controller hierarchy.
        $rawLimit = @file_get_contents('/sys/fs/cgroup/memory/memory.limit_in_bytes');
        if ($rawLimit !== false) {
            $limStr = trim($rawLimit);
            if (ctype_digit($limStr)) {
                $maxV = (int) $limStr;
                if ($maxV > 0 && $maxV < self::CGROUP_V1_UNLIMITED) {
                    $rawUsage = @file_get_contents('/sys/fs/cgroup/memory/memory.usage_in_bytes');
                    if ($rawUsage !== false) {
                        $useStr = trim($rawUsage);
                        if (ctype_digit($useStr)) {
                            return [(int) $useStr, $maxV];
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Number of online CPUs from /proc/cpuinfo. Unknown stays unknown: callers
     * omit the bound rather than guessing a single-core host.
     */
    private static function cpuCount(): ?int
    {
        $cpuinfo = @file_get_contents('/proc/cpuinfo');
        if ($cpuinfo !== false && $cpuinfo !== '') {
            $count = preg_match_all('/^processor\s*:/m', $cpuinfo);
            if (is_int($count) && $count > 0) {
                return $count;
            }
        }

        return null;
    }

    // ── Helpers (numeric formatting ported byte-for-byte from the siblings) ──

    /**
     * Least-squares slope of MB over minutes for a series of {at(ms),mb} points.
     *
     * @param list<array{at:int,mb:float}> $samples
     */
    private static function slopePerMin(array $samples): float
    {
        $n = count($samples);
        if ($n < 2) {
            return 0.0;
        }
        $t0 = $samples[0]['at'];
        $sx = $sy = $sxx = $sxy = 0.0;
        foreach ($samples as $s) {
            $x = ($s['at'] - $t0) / 60000.0;
            $y = $s['mb'];
            $sx += $x;
            $sy += $y;
            $sxx += $x * $x;
            $sxy += $x * $y;
        }
        $denom = $n * $sxx - $sx * $sx;
        if ($denom == 0.0) {
            return 0.0;
        }

        return ($n * $sxy - $sx * $sy) / $denom;
    }

    /** @return array{float,float} slope and its standard error, in MB/min */
    private static function slopeAndSePerMin(array $samples): array
    {
        $slope = self::slopePerMin($samples);
        $n = count($samples);
        if ($n < 3) {
            return [$slope, INF];
        }
        $t0 = (int) $samples[0]['at'];
        $xs = [];
        $ys = [];
        foreach ($samples as $s) {
            $xs[] = ((int) $s['at'] - $t0) / 60_000.0;
            $ys[] = (float) $s['mb'];
        }
        $meanX = array_sum($xs) / $n;
        $meanY = array_sum($ys) / $n;
        $sxx = 0.0;
        $sse = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dx = $xs[$i] - $meanX;
            $sxx += $dx * $dx;
            $residual = $ys[$i] - ($meanY + $slope * $dx);
            $sse += $residual * $residual;
        }
        $se = $sxx > 0 ? sqrt(($sse / ($n - 2)) / $sxx) : INF;
        return [$slope, $se];
    }

    private static function r0(float $n): int
    {
        return (int) round($n);
    }

    private static function r1(float $n): float
    {
        return round($n * 10) / 10.0;
    }

    private static function r2(float $n): float
    {
        return round($n * 100) / 100.0;
    }

    /**
     * Stringify a rounded number as JS Number-to-string does for these captions:
     * "2" not "2.0", "0.5" not "0.50". Already r1/r2-rounded on the way in.
     */
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

    /** Signed 1dp string, always carrying a leading sign ("+2.5", "-1", "+0"). */
    private static function signed(float $n): string
    {
        $v = self::r1($n);
        if ($v == 0.0) {
            $v = 0.0;
        }

        return $v >= 0 ? '+' . self::numStr($v) : self::numStr($v);
    }

    /**
     * The current stored sample ring, oldest-first. Empty when state is
     * unavailable (which just keeps every ring-gated axis honestly absent).
     *
     * @return list<array<string,int>>
     */
    private static function ring(): array
    {
        $ring = Store::get(self::STORE_KEY, []);

        return is_array($ring) ? array_values($ring) : [];
    }
}
