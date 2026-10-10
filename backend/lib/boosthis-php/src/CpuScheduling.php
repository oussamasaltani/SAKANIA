<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * Additive processor/scheduling readings. PHP has no post-response sampler
 * process, so these observations ride the existing request-boundary sample and
 * keep their anchors in Store, the kit's one cross-request backend.
 */
final class CpuScheduling
{
    public const ENTITLEMENT_GOOD_RATIO = 1.0;
    public const ENTITLEMENT_POOR_RATIO = 4.0;
    public const THROTTLING_GOOD_PCT = 1.0;
    public const THROTTLING_POOR_PCT = 25.0;
    public const THROTTLING_MIN_SPAN_MS = 15_000;
    public const CONTEXT_GOOD_PCT = 10.0;
    public const CONTEXT_POOR_PCT = 60.0;
    public const CONTEXT_MIN_SPAN_MS = 60_000;
    public const CONTEXT_MIN_SWITCHES = 500;
    public const RESTARTS_GOOD_PER_HOUR = 2.0;
    public const RESTARTS_POOR_PER_HOUR = 12.0;
    public const WORKER_MIN_SPAN_MS = 600_000;
    public const IMBALANCE_GOOD_RATIO = 1.5;
    public const IMBALANCE_POOR_RATIO = 4.0;
    public const IMBALANCE_MIN_WORKERS = 2;

    private const THROTTLE_KEY = 'cpu:throttling';
    private const CONTEXT_KEY = 'cpu:context';
    private const WORKERS_KEY = 'cpu:workers';
    private const WORKER_LEDGER_CAP = 256;

    private function __construct()
    {
    }

    /** Called beside the kit's existing request-boundary samplers. */
    public static function sample(): void
    {
        if (RuntimeFlags::disabled()) {
            return;
        }
        Safe::fire(static function (): void {
            $now = self::monotonicMs();
            $throttle = self::readThrottleCounters();
            if ($throttle !== null) {
                self::recordThrottleSample($now, $throttle[0], $throttle[1]);
            }
            $context = self::readContextCounters();
            if ($context !== null) {
                self::recordContextSample($now, $context[0], $context[1]);
            }
            $pid = getmypid();
            if (is_int($pid) && $pid > 0) {
                self::recordWorkerSample($now, $pid, self::workerBootMs($now));
            }
        });
    }

    /** @param array<string,mixed> $axes */
    public static function addReadings(array &$axes): void
    {
        if (RuntimeFlags::disabled()) {
            return;
        }
        $add = static function (array &$target, string $key, ?array $axis): void {
            if ($axis !== null) {
                $target[$key] = $axis;
            }
        };
        Safe::fire(static function () use (&$axes, $add): void {
            $add($axes, 'cpuEntitlement', self::cpuEntitlement());
            $add($axes, 'cpuThrottling', self::cpuThrottling());
            $add($axes, 'contextSwitching', self::contextSwitching());
            $add($axes, 'workerRestarts', self::workerRestarts());
            $add($axes, 'workerImbalance', self::workerImbalance());
        });
    }

    private static function cpuEntitlement(): ?array
    {
        $visible = self::visibleCpuCount();
        if ($visible === null) {
            return null;
        }
        $v2 = self::readQuotaFile('/sys/fs/cgroup/cpu.max', true);
        if ($v2 !== null) {
            return self::cpuEntitlementFromQuota($visible, $v2[0], $v2[1], 'cgroup2');
        }
        foreach ([
            ['/sys/fs/cgroup/cpu/cpu.cfs_quota_us', '/sys/fs/cgroup/cpu/cpu.cfs_period_us'],
            ['/sys/fs/cgroup/cpu,cpuacct/cpu.cfs_quota_us', '/sys/fs/cgroup/cpu,cpuacct/cpu.cfs_period_us'],
        ] as [$quotaPath, $periodPath]) {
            $quota = @file_get_contents($quotaPath);
            $period = @file_get_contents($periodPath);
            if (is_string($quota) && is_string($period)
                && is_numeric(trim($quota)) && ctype_digit(trim($period))) {
                $axis = self::cpuEntitlementFromQuota(
                    $visible,
                    (float) trim($quota),
                    (float) trim($period),
                    'cgroup1'
                );
                if ($axis !== null) {
                    return $axis;
                }
            }
        }
        return null;
    }

    private static function cpuEntitlementFromQuota(
        int $visible,
        float $quota,
        float $period,
        string $source
    ): ?array {
        if ($visible < 1 || $quota <= 0 || $period <= 0) {
            return null;
        }
        $allowed = $quota / $period;
        $ratio = $visible / $allowed;
        $score = Score::scoreMetric(
            $ratio,
            self::ENTITLEMENT_GOOD_RATIO,
            self::ENTITLEMENT_POOR_RATIO
        );
        return [
            'score' => $score,
            'rating' => Score::getRating($score),
            'visibleCpus' => $visible,
            'allowedCpus' => self::r2($allowed),
            'mismatchRatio' => self::r2($ratio),
            'sourceCode' => $source,
            'caption' => self::r2($allowed) . ' of ' . $visible . ' visible CPUs allowed',
        ];
    }

    /** @return array{0:float,1:float}|null */
    private static function readQuotaFile(string $path, bool $combined): ?array
    {
        $raw = @file_get_contents($path);
        if (!is_string($raw) || !$combined) {
            return null;
        }
        $parts = preg_split('/\s+/', trim($raw)) ?: [];
        if (count($parts) < 2 || $parts[0] === 'max'
            || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
            return null;
        }
        return [(float) $parts[0], (float) $parts[1]];
    }

    private static function recordThrottleSample(int $now, int $periods, int $throttled): void
    {
        Store::update(self::THROTTLE_KEY, static function ($cur) use ($now, $periods, $throttled): array {
            $state = is_array($cur) ? $cur : [];
            $anchor = is_array($state['anchor'] ?? null) ? $state['anchor'] : null;
            if ($anchor === null
                || $periods < (int) ($anchor['periods'] ?? 0)
                || $throttled < (int) ($anchor['throttled'] ?? 0)
                || $now <= (int) ($anchor['at'] ?? 0)) {
                return ['anchor' => ['at' => $now, 'periods' => $periods, 'throttled' => $throttled]];
            }
            $span = $now - (int) $anchor['at'];
            if ($span < self::THROTTLING_MIN_SPAN_MS) {
                return $state;
            }
            $deltaPeriods = $periods - (int) $anchor['periods'];
            $deltaThrottled = $throttled - (int) $anchor['throttled'];
            return [
                'anchor' => ['at' => $now, 'periods' => $periods, 'throttled' => $throttled],
                'reading' => [
                    'span' => $span,
                    'periods' => $deltaPeriods,
                    'throttled' => $deltaThrottled,
                ],
            ];
        }, []);
    }

    private static function cpuThrottling(): ?array
    {
        $state = Store::get(self::THROTTLE_KEY, []);
        $reading = is_array($state) && is_array($state['reading'] ?? null) ? $state['reading'] : null;
        if ($reading === null || (int) ($reading['periods'] ?? 0) <= 0) {
            return null;
        }
        $periods = (int) $reading['periods'];
        $throttled = (int) ($reading['throttled'] ?? 0);
        $pct = 100.0 * $throttled / $periods;
        $score = Score::scoreMetric($pct, self::THROTTLING_GOOD_PCT, self::THROTTLING_POOR_PCT);
        return [
            'score' => $score,
            'rating' => Score::getRating($score),
            'throttledPct' => self::r1($pct),
            'periods' => $periods,
            'throttledPeriods' => $throttled,
            'windowSec' => self::r1((int) $reading['span'] / 1000.0),
            'caption' => self::r1($pct) . '% of periods throttled',
        ];
    }

    private static function recordContextSample(int $now, int $voluntary, int $involuntary): void
    {
        Store::update(self::CONTEXT_KEY, static function ($cur) use ($now, $voluntary, $involuntary): array {
            $state = is_array($cur) ? $cur : [];
            $anchor = is_array($state['anchor'] ?? null) ? $state['anchor'] : null;
            if ($anchor === null
                || $voluntary < (int) ($anchor['voluntary'] ?? 0)
                || $involuntary < (int) ($anchor['involuntary'] ?? 0)
                || $now <= (int) ($anchor['at'] ?? 0)) {
                return ['anchor' => [
                    'at' => $now, 'voluntary' => $voluntary, 'involuntary' => $involuntary,
                ]];
            }
            $span = $now - (int) $anchor['at'];
            $deltaVoluntary = $voluntary - (int) $anchor['voluntary'];
            $deltaInvoluntary = $involuntary - (int) $anchor['involuntary'];
            $total = $deltaVoluntary + $deltaInvoluntary;
            if ($span < self::CONTEXT_MIN_SPAN_MS || $total < self::CONTEXT_MIN_SWITCHES) {
                return $state;
            }
            return [
                'anchor' => [
                    'at' => $now, 'voluntary' => $voluntary, 'involuntary' => $involuntary,
                ],
                'reading' => ['span' => $span, 'total' => $total, 'involuntary' => $deltaInvoluntary],
            ];
        }, []);
    }

    private static function contextSwitching(): ?array
    {
        $state = Store::get(self::CONTEXT_KEY, []);
        $reading = is_array($state) && is_array($state['reading'] ?? null) ? $state['reading'] : null;
        if ($reading === null || (int) ($reading['total'] ?? 0) < self::CONTEXT_MIN_SWITCHES) {
            return null;
        }
        $total = (int) $reading['total'];
        $span = (int) $reading['span'];
        $pct = 100.0 * (int) $reading['involuntary'] / $total;
        $rate = $total / ($span / 1000.0);
        $score = Score::scoreMetric($pct, self::CONTEXT_GOOD_PCT, self::CONTEXT_POOR_PCT);
        return [
            'score' => $score,
            'rating' => Score::getRating($score),
            'involuntaryPct' => self::r1($pct),
            'switchesPerSec' => self::r1($rate),
            'windowMin' => self::r1($span / 60_000.0),
            'caption' => self::r1($pct) . '% involuntary switches',
        ];
    }

    private static function recordWorkerSample(int $now, int $pid, int $bootAt): void
    {
        Store::update(self::WORKERS_KEY, static function ($cur) use ($now, $pid, $bootAt): array {
            $state = is_array($cur) ? $cur : [];
            $startedAt = (int) ($state['startedAt'] ?? $now);
            $workers = is_array($state['workers'] ?? null) ? array_values($state['workers']) : [];
            $found = false;
            foreach ($workers as &$worker) {
                if ((int) ($worker['pid'] ?? 0) === $pid && (int) ($worker['bootAt'] ?? -1) === $bootAt) {
                    $worker['completed'] = (int) ($worker['completed'] ?? 0) + 1;
                    $worker['lastSeen'] = $now;
                    $found = true;
                    break;
                }
            }
            unset($worker);
            if (!$found) {
                $workers[] = [
                    'pid' => $pid,
                    'bootAt' => $bootAt,
                    'firstSeen' => $now,
                    'lastSeen' => $now,
                    'completed' => 1,
                ];
            }
            if (count($workers) > self::WORKER_LEDGER_CAP) {
                $workers = array_slice($workers, -self::WORKER_LEDGER_CAP);
            }
            return ['startedAt' => $startedAt, 'lastAt' => $now, 'workers' => $workers];
        }, []);
    }

    private static function workerRestarts(): ?array
    {
        $state = self::workerState();
        if ($state === null) {
            return null;
        }
        $startedAt = (int) $state['startedAt'];
        $span = (int) $state['lastAt'] - $startedAt;
        $count = 0;
        foreach ($state['workers'] as $worker) {
            if ((int) ($worker['bootAt'] ?? 0) > $startedAt) {
                $count++;
            }
        }
        $perHour = $count / ($span / 3_600_000.0);
        $score = Score::scoreMetric(
            $perHour,
            self::RESTARTS_GOOD_PER_HOUR,
            self::RESTARTS_POOR_PER_HOUR
        );
        return [
            'score' => $score,
            'rating' => Score::getRating($score),
            'restartsPerHour' => self::r1($perHour),
            'count' => $count,
            'windowMin' => self::r1($span / 60_000.0),
            'caption' => self::r1($perHour) . ' worker restarts/hour',
        ];
    }

    private static function workerImbalance(): ?array
    {
        $state = self::workerState();
        if ($state === null || count($state['workers']) < self::IMBALANCE_MIN_WORKERS) {
            return null;
        }
        $counts = array_map(
            static fn (array $worker): int => max(0, (int) ($worker['completed'] ?? 0)),
            $state['workers']
        );
        $total = array_sum($counts);
        if ($total <= 0) {
            return null;
        }
        $workers = count($counts);
        $busiest = max($counts);
        $ratio = $busiest / ($total / $workers);
        $share = 100.0 * $busiest / $total;
        $span = (int) $state['lastAt'] - (int) $state['startedAt'];
        $score = Score::scoreMetric(
            $ratio,
            self::IMBALANCE_GOOD_RATIO,
            self::IMBALANCE_POOR_RATIO
        );
        return [
            'score' => $score,
            'rating' => Score::getRating($score),
            'imbalanceRatio' => self::r2($ratio),
            'workerCount' => $workers,
            'busiestShare' => self::r1($share),
            'windowMin' => self::r1($span / 60_000.0),
            'caption' => self::r2($ratio) . '× busiest worker',
        ];
    }

    private static function workerState(): ?array
    {
        $state = Store::get(self::WORKERS_KEY, []);
        if (!is_array($state) || !is_array($state['workers'] ?? null)
            || (int) ($state['lastAt'] ?? 0) - (int) ($state['startedAt'] ?? 0)
                < self::WORKER_MIN_SPAN_MS) {
            return null;
        }
        return $state;
    }

    /** @return array{0:int,1:int}|null */
    private static function readThrottleCounters(): ?array
    {
        foreach (['/sys/fs/cgroup/cpu.stat', '/sys/fs/cgroup/cpu/cpu.stat', '/sys/fs/cgroup/cpu,cpuacct/cpu.stat'] as $path) {
            $raw = @file_get_contents($path);
            if (!is_string($raw)) {
                continue;
            }
            if (preg_match('/^nr_periods\s+(\d+)$/m', $raw, $periods) === 1
                && preg_match('/^nr_throttled\s+(\d+)$/m', $raw, $throttled) === 1) {
                return [(int) $periods[1], (int) $throttled[1]];
            }
        }
        return null;
    }

    /** @return array{0:int,1:int}|null */
    private static function readContextCounters(): ?array
    {
        $raw = @file_get_contents('/proc/self/status');
        if (!is_string($raw)
            || preg_match('/^voluntary_ctxt_switches:\s*(\d+)$/m', $raw, $voluntary) !== 1
            || preg_match('/^nonvoluntary_ctxt_switches:\s*(\d+)$/m', $raw, $involuntary) !== 1) {
            return null;
        }
        return [(int) $voluntary[1], (int) $involuntary[1]];
    }

    private static function visibleCpuCount(): ?int
    {
        $raw = @file_get_contents('/proc/cpuinfo');
        if (!is_string($raw)) {
            return null;
        }
        $count = preg_match_all('/^processor\s*:/m', $raw);
        return is_int($count) && $count > 0 ? $count : null;
    }

    /** Monotonic on Linux and comparable across FPM worker processes. */
    private static function monotonicMs(): int
    {
        return (int) floor(hrtime(true) / 1_000_000);
    }

    /** Worker boot on the same monotonic-since-host-boot clock. */
    private static function workerBootMs(int $now): int
    {
        $stat = @file_get_contents('/proc/self/stat');
        $uptime = @file_get_contents('/proc/uptime');
        if (!is_string($stat) || !is_string($uptime)) {
            return $now;
        }
        $end = strrpos($stat, ')');
        $fields = $end === false ? [] : (preg_split('/\s+/', trim(substr($stat, $end + 1))) ?: []);
        $uptimeParts = preg_split('/\s+/', trim($uptime)) ?: [];
        if (!isset($fields[19], $uptimeParts[0]) || !is_numeric($fields[19]) || !is_numeric($uptimeParts[0])) {
            return $now;
        }
        // Linux USER_HZ is 100 on the PHP/FPM hosts where /proc supplies this source.
        $ageMs = max(0.0, ((float) $uptimeParts[0] - ((float) $fields[19] / 100.0)) * 1000.0);
        return max(0, $now - (int) round($ageMs));
    }

    private static function r1(float $n): float
    {
        return round($n * 10) / 10.0;
    }

    private static function r2(float $n): float
    {
        return round($n * 100) / 100.0;
    }
}