<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * The perf snapshot: what this app looks like right now, as a closed payload on
 * exactly the top-level fields the server's ingest accepts.
 *
 * Screen-bearing by definition, so it only moves when full telemetry is on —
 * the owner's own opt-in, or the server directive learned at consent.
 * BOOSTHIS_DISABLED and forget() always win.
 */
final class Snapshot
{
    /** Steady-state re-upload interval once the first-run turbo ramp is done. */
    public const SNAPSHOT_FLUSH_MS = 60000;

    /**
     * BOOSTHIS_TURBO=0 (and only that exact value) opts an install OUT of the
     * first-run ramp, falling back to the steady 60s cadence from boot.
     * Everything else — unset included — leaves the ramp ON.
     */
    public const TURBO_ENV_VAR = 'BOOSTHIS_TURBO';
    public const TURBO_OFF = '0';

    /** Route rows carry this prefix in every runtime, so live-data tools match. */
    public const SCREEN_PREFIX = 'screen:';
    /** Cap rows + findings so a busy app can never ship an unbounded payload. */
    public const MAX_SNAPSHOT_ROWS = 100;
    public const MAX_SNAPSHOT_FINDINGS = 20;
    /** A route slower than this at p95 is worth calling out. */
    public const SLOW_API_P95_MS = 1000;

    private const KEY_LAST_UPLOAD = 'snapshot_last_upload';
    private const KEY_STARTED_AT = 'started_at';

    private function __construct()
    {
    }

    public static function turboDisabled(): bool
    {
        $v = RuntimeFlags::env(self::TURBO_ENV_VAR);

        return $v !== null && trim($v) === self::TURBO_OFF;
    }

    /**
     * Delay (ms) until the NEXT upload, based purely on how long this install
     * has been running:
     *   age < 30s  -> 10s
     *   age < 90s  -> 20s
     *   age < 180s -> 40s
     *   otherwise  -> the steady 60s
     *
     * Age-driven ON PURPOSE: a skipped or empty upload must not burn a ramp
     * step, so the schedule is a function of age alone. Byte-identical
     * semantics to the sibling runtimes. This changes only how soon existing
     * data reaches the server — it touches no honesty gate.
     */
    public static function nextSnapshotDelayMs(int $sessionAgeMs): int
    {
        if (self::turboDisabled()) {
            return self::SNAPSHOT_FLUSH_MS;
        }
        if ($sessionAgeMs < 30000) {
            return 10000;
        }
        if ($sessionAgeMs < 90000) {
            return 20000;
        }
        if ($sessionAgeMs < 180000) {
            return 40000;
        }

        return self::SNAPSHOT_FLUSH_MS;
    }

    /**
     * When this install first ran in this project scope. PHP has no process
     * lifetime to measure against, so "session" means the install's own age —
     * which is the honest equivalent and survives worker recycling.
     */
    public static function startedAtMs(): int
    {
        $v = Store::get(self::KEY_STARTED_AT, null);
        if (is_int($v)) {
            return $v;
        }
        $now = Telemetry::nowMs();
        Store::set(self::KEY_STARTED_AT, $now);

        return $now;
    }

    public static function sessionAgeMs(): int
    {
        return max(0, Telemetry::nowMs() - self::startedAtMs());
    }

    /** True when the cadence says another upload is due. */
    public static function uploadDue(): bool
    {
        $last = Store::get(self::KEY_LAST_UPLOAD, 0);
        $last = is_int($last) ? $last : 0;
        if ($last === 0) {
            return true;
        }

        return (Telemetry::nowMs() - $last) >= self::nextSnapshotDelayMs(self::sessionAgeMs());
    }

    public static function markUploaded(): void
    {
        Store::set(self::KEY_LAST_UPLOAD, Telemetry::nowMs());
    }

    /**
     * Build the current privacy-safe snapshot as a closed map on the EXACT
     * allowlisted top-level fields the server accepts. Pure: reads stored
     * samples and meter state, performs no network I/O.
     *
     * @return array<string,mixed>
     */
    public static function capturePerfSnapshot(): array
    {
        $samples = Samples::all();
        $rows = self::buildRows($samples);

        $snap = [];
        $snap['capturedAt'] = Telemetry::nowMs();
        $snap['runtimeVersion'] = self::pep440ToSemver(Thresholds::RUNTIME_VERSION);
        $snap['platform'] = 'php';
        $snap['startedAt'] = self::startedAtMs();
        $snap['totalEvents'] = count($samples);
        $snap['rows'] = $rows;

        $crossCutting = self::buildCrossCutting($rows);
        foreach (LiveDetectors::collectDetectorFindings() as $finding) {
            if (count($crossCutting) >= self::MAX_SNAPSHOT_FINDINGS) {
                break;
            }
            $crossCutting[] = $finding;
        }
        $snap['crossCutting'] = $crossCutting;
        $snap['axes'] = Json::objectOrEmpty(self::buildAxes($samples));

        $burstPeak = LiveDetectors::peakRetryBurst();
        if ($burstPeak > 0) {
            // Anonymous numeric-only live-event counters. Omitted while zero.
            $snap['events'] = ['retryBurstMax10s' => $burstPeak];
        }

        $build = BuildIdentity::buildInfo();
        if ($build !== null) {
            $snap['build'] = $build;
        }
        $deps = BuildIdentity::deps();
        if ($deps !== null) {
            $snap['deps'] = $deps;
        }
        // Always carry the capability answer. Missing means an older kit;
        // unsupported, unreadable and off are three explicit kinds of silence.
        $snap['routeList'] = RouteInventory::routeListForSnapshot();

        return $snap;
    }

    /**
     * One row per route: count, p50, p95, max and the most recent duration.
     *
     * @param list<array{route:string,durationMs:int,at:int}> $samples
     * @return list<array{key:string,count:int,p50:int,p95:int,max:int,last:int}>
     */
    public static function buildRows(array $samples): array
    {
        $byRoute = [];
        foreach ($samples as $s) {
            $byRoute[$s['route']][] = $s['durationMs'];
        }
        $rows = [];
        foreach ($byRoute as $route => $durations) {
            $rows[] = [
                'key' => self::SCREEN_PREFIX . $route,
                'count' => count($durations),
                'p50' => (int) round(Samples::percentile($durations, 0.5)),
                'p95' => (int) round(Samples::percentile($durations, 0.95)),
                'max' => (int) max($durations),
                'last' => (int) $durations[count($durations) - 1],
            ];
        }
        // Busiest routes first, so the cap keeps what matters.
        usort($rows, static fn ($a, $b) => $b['count'] <=> $a['count']);

        return array_slice($rows, 0, self::MAX_SNAPSHOT_ROWS);
    }

    /**
     * Cross-cutting findings: routes whose p95 is over the slow-API line.
     *
     * @param list<array{key:string,count:int,p50:int,p95:int,max:int,last:int}> $rows
     * @return list<array{kind:string,name:string,p95:int,count:int}>
     */
    public static function buildCrossCutting(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if ($row['p95'] >= self::SLOW_API_P95_MS) {
                $out[] = [
                    'kind' => 'slow-api',
                    'name' => $row['key'],
                    'p95' => $row['p95'],
                    'count' => $row['count'],
                ];
            }
            if (count($out) >= self::MAX_SNAPSHOT_FINDINGS) {
                break;
            }
        }

        return $out;
    }

    /**
     * Every axis this runtime can honestly report right now. Each one gates
     * itself and is simply absent while it is still warming up.
     *
     * @param list<array{route:string,durationMs:int,at:int}> $samples
     * @return array<string,mixed>
     */
    public static function buildAxes(array $samples): array
    {
        $axes = [];
        $durations = array_map(static fn ($s) => $s['durationMs'], $samples);

        // Core responsiveness — the only axis that maps to the Speed score.
        if (count($durations) >= 5) {
            $p75 = Samples::percentile($durations, 0.75);
            $score = Score::scoreMetric($p75, Thresholds::TTI_GOOD, Thresholds::TTI_POOR);
            $axes['responsiveness'] = [
                'score' => $score,
                'rating' => Score::getRating($score),
                'p75Ms' => (int) round($p75),
                'count' => count($durations),
            ];

            if (count($durations) >= Thresholds::RESILIENCE_MIN_TAIL_SAMPLES) {
                $p50 = Samples::percentile($durations, 0.5);
                $p99 = Samples::percentile($durations, 0.99);

                // Resilience tail contract: tiny tails and unmeasured medians made the old ratio dishonest.
                if ($p99 <= Thresholds::RESILIENCE_TAIL_FLOOR_MS) {
                    $tailRatio = $p50 >= Thresholds::RESILIENCE_MIN_MEDIAN_MS ? $p99 / $p50 : null;
                    $axes['resilience'] = [
                        'score' => 100,
                        'rating' => Score::getRating(100),
                        'tailRatio' => $tailRatio === null ? null : round($tailRatio * 10) / 10,
                        'p50Ms' => (int) round($p50),
                        'p99Ms' => (int) round($p99),
                        'sampleCount' => count($durations),
                    ];
                } elseif ($p50 < Thresholds::RESILIENCE_MIN_MEDIAN_MS) {
                    $axes['resilience'] = [
                        'score' => null,
                        'rating' => 'pending',
                        'tailRatio' => null,
                        'p50Ms' => (int) round($p50),
                        'p99Ms' => (int) round($p99),
                        'sampleCount' => count($durations),
                    ];
                } else {
                    $tailRatio = $p99 / $p50;
                    $resScore = Score::scoreMetric($tailRatio, 2.0, 10.0);
                    $axes['resilience'] = [
                        'score' => $resScore,
                        'rating' => Score::getRating($resScore),
                        'tailRatio' => round($tailRatio * 10) / 10,
                        'p50Ms' => (int) round($p50),
                        'p99Ms' => (int) round($p99),
                        'sampleCount' => count($durations),
                    ];
                }
                HeldOpen::addFields($axes['resilience']);
            }
        }

        RuntimeVitals::addVitals($axes);
        MeterAxes::addMeterAxes($axes, $samples);
        ExtraMeters::addExtraMeters($axes);
        HostResources::addHostResources($axes);
        CpuScheduling::addReadings($axes);
        RequestErrorTimerReadings::addAxes($axes);

        return $axes;
    }

    /** Is there anything worth uploading? An empty snapshot is never shipped. */
    public static function worthUploading(): bool
    {
        return Samples::count() > 0 || AiCalls::hasData();
    }

    /**
     * PEP440-style kit version -> semver, so every runtime reports its version
     * in the same shape on the wire (1.0.0a1 -> 1.0.0-alpha.1).
     */
    public static function pep440ToSemver(string $v): string
    {
        if (preg_match('/^(\d+\.\d+\.\d+)a(\d+)$/', $v, $m) === 1) {
            return $m[1] . '-alpha.' . $m[2];
        }
        if (preg_match('/^(\d+\.\d+\.\d+)b(\d+)$/', $v, $m) === 1) {
            return $m[1] . '-beta.' . $m[2];
        }

        return $v;
    }
}
