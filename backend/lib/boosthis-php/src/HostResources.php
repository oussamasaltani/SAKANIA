<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * Additive, display-only host resource readings. The rate readings difference
 * two /proc samples from the same PHP worker; descriptor targets and paths are
 * never retained or emitted.
 */
final class HostResources
{
    private const DISK_USED_GOOD_PCT = 75.0;
    private const DISK_USED_POOR_PCT = 95.0;
    private const IO_GOOD_MB_PER_SEC = 2.0;
    private const IO_POOR_MB_PER_SEC = 100.0;
    private const IO_MIN_SPAN_MS = 60_000;
    private const FAULT_GOOD_PER_MIN = 1.0;
    private const FAULT_POOR_PER_MIN = 100.0;
    private const FAULT_MIN_SPAN_MS = 60_000;
    private const FDMIX_SOCK_PCT_GOOD = 70.0;
    private const FDMIX_SOCK_PCT_POOR = 95.0;
    private const FDMIX_MIN_FDS = 20;
    private const FDMIX_SCAN_CAP = 4096;

    // kit-probe-file observation point: 8/120ms, 1%/10%, gate 5, ring 200.
    public const STORAGE_LATENCY_GOOD_MS = 8.0;
    public const STORAGE_LATENCY_POOR_MS = 120.0;
    public const STORAGE_FAILURE_GOOD_PCT = 1.0;
    public const STORAGE_FAILURE_POOR_PCT = 10.0;
    public const STORAGE_MIN_OPERATIONS = 5;
    public const STORAGE_RING_CAP = 200;

    private const SAMPLE_RING_CAP = 240;
    private const IO_KEY = 'host:io';
    private const FAULT_KEY = 'host:faults';
    private const STORAGE_KEY = 'host:storageProbe';
    private const BYTES_PER_MB = 1048576.0;

    private static ?bool $containerised = null;
    /** @var array{sockets:int,pipes:int,files:int,other:int}|null */
    private static ?array $fdMixForTests = null;

    private function __construct()
    {
    }

    /** Called on the existing request-boundary sampling call. */
    public static function sample(): void
    {
        if (RuntimeFlags::disabled()) {
            return;
        }
        Safe::fire(static function (): void {
            $now = Telemetry::nowMs();
            $pid = getmypid();
            if (is_int($pid) && $pid > 0) {
                $io = self::readProcIo();
                if ($io !== null) {
                    self::push(self::IO_KEY, [
                        'at' => $now, 'pid' => $pid,
                        'read' => $io[0], 'write' => $io[1],
                    ]);
                }
                $major = self::readMajorFaults();
                if ($major !== null) {
                    self::push(self::FAULT_KEY, [
                        'at' => $now, 'pid' => $pid, 'major' => $major,
                    ]);
                }
            }
            self::sampleStorage();
        });
    }

    /** @param array<string,mixed> $axes */
    public static function addHostResources(array &$axes): void
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
            $add($axes, 'diskPressure', self::diskPressure());
            $add($axes, 'storageLatency', self::storageLatency());
            $add($axes, 'storageFailures', self::storageFailures());
            $add($axes, 'ioPressure', self::ioPressure());
            $add($axes, 'pageFaults', self::pageFaults());
            $add($axes, 'descriptorMix', self::descriptorMix());
        });
    }

    /** @param array{sockets:int,pipes:int,files:int,other:int}|null $mix */
    public static function setFdMixForTests(?array $mix): void
    {
        self::$fdMixForTests = $mix;
    }

    private static function diskPressure(): ?array
    {
        $dir = Store::dir();
        $total = @disk_total_space($dir);
        $free = @disk_free_space($dir);
        if ($total === false || $free === false || $total <= 0 || $free < 0) {
            return null;
        }
        $usedPct = 100.0 * ($total - $free) / $total;
        $score = Score::scoreMetric($usedPct, self::DISK_USED_GOOD_PCT, self::DISK_USED_POOR_PCT);

        return [
            'score' => $score, 'rating' => Score::getRating($score),
            'usedPct' => self::r1($usedPct),
            'freeMb' => (int) round($free / self::BYTES_PER_MB),
            'totalMb' => (int) round($total / self::BYTES_PER_MB),
            'scopeCode' => self::volumeScope(),
            'caption' => self::r1($usedPct) . '% used',
        ];
    }

    private static function ioPressure(): ?array
    {
        $pair = self::sameProcessWindow(self::IO_KEY, self::IO_MIN_SPAN_MS);
        if ($pair === null) {
            return null;
        }
        [$first, $last, $span] = $pair;
        $read = max(0, (int) $last['read'] - (int) $first['read']);
        $write = max(0, (int) $last['write'] - (int) $first['write']);
        $rate = (($read + $write) / self::BYTES_PER_MB) / ($span / 1000.0);
        $score = Score::scoreMetric($rate, self::IO_GOOD_MB_PER_SEC, self::IO_POOR_MB_PER_SEC);

        return [
            'score' => $score, 'rating' => Score::getRating($score),
            'mbPerSec' => self::r2($rate),
            'readMb' => (int) round($read / self::BYTES_PER_MB),
            'writtenMb' => (int) round($write / self::BYTES_PER_MB),
            'windowMin' => self::r1($span / 60000.0),
            'scopeCode' => 1, 'caption' => self::r2($rate) . ' MB/s storage I/O',
        ];
    }

    private static function pageFaults(): ?array
    {
        $pair = self::sameProcessWindow(self::FAULT_KEY, self::FAULT_MIN_SPAN_MS);
        if ($pair === null) {
            return null;
        }
        [$first, $last, $span] = $pair;
        $major = max(0, (int) $last['major'] - (int) $first['major']);
        $perMin = $major / ($span / 60000.0);
        $score = Score::scoreMetric($perMin, self::FAULT_GOOD_PER_MIN, self::FAULT_POOR_PER_MIN);

        return [
            'score' => $score, 'rating' => Score::getRating($score),
            'majorFaults' => $major, 'majorPerMin' => self::r1($perMin),
            'windowMin' => self::r1($span / 60000.0),
            'scopeCode' => 1, 'caption' => self::r1($perMin) . ' major faults/min',
        ];
    }

    private static function descriptorMix(): ?array
    {
        if (self::$fdMixForTests !== null) {
            $counts = self::$fdMixForTests;
            return self::descriptorMixFromCounts($counts);
        }
        $names = @scandir('/proc/self/fd');
        if (!is_array($names)) {
            return null;
        }
        $counts = ['sockets' => 0, 'pipes' => 0, 'files' => 0, 'other' => 0];
        $seen = 0;
        foreach ($names as $name) {
            if ($name === '.' || $name === '..' || $seen >= self::FDMIX_SCAN_CAP) {
                continue;
            }
            $seen++;
            $stat = @stat('/proc/self/fd/' . $name);
            if (!is_array($stat) || !isset($stat['mode'])) {
                continue;
            }
            $kind = ((int) $stat['mode']) & 0170000;
            if ($kind === 0140000) {
                $counts['sockets']++;
            } elseif ($kind === 0010000) {
                $counts['pipes']++;
            } elseif ($kind === 0100000) {
                $counts['files']++;
            } else {
                $counts['other']++;
            }
        }
        return self::descriptorMixFromCounts($counts);
    }

    /** @param array{sockets:int,pipes:int,files:int,other:int} $counts */
    private static function descriptorMixFromCounts(array $counts): ?array
    {
        $total = array_sum($counts);
        if ($total < self::FDMIX_MIN_FDS) {
            return null;
        }
        $pct = 100.0 * $counts['sockets'] / $total;
        $score = Score::scoreMetric($pct, self::FDMIX_SOCK_PCT_GOOD, self::FDMIX_SOCK_PCT_POOR);

        return [
            'score' => $score, 'rating' => Score::getRating($score),
            'sockets' => $counts['sockets'], 'pipes' => $counts['pipes'],
            'files' => $counts['files'], 'otherFds' => $counts['other'],
            'socketsPct' => (int) round($pct), 'scopeCode' => 1,
            'caption' => $counts['sockets'] . ' sockets',
        ];
    }

    private static function sampleStorage(): void
    {
        if (RuntimeFlags::stateDir() === null) {
            return;
        }
        $state = Store::get(self::STORAGE_KEY, []);
        $state = is_array($state) ? $state : [];
        $state['attempts'] = (int) ($state['attempts'] ?? 0) + 1;
        $path = Store::dir() . DIRECTORY_SEPARATOR . '.boosthis-io-probe-' . (int) getmypid();
        $start = hrtime(true);
        $ok = false;
        try {
            $written = @file_put_contents($path, 'boosthis-probe', LOCK_EX);
            $read = $written === false ? false : @file_get_contents($path);
            $ok = $written !== false && $read === 'boosthis-probe';
        } finally {
            @unlink($path);
        }
        if ($ok) {
            $ms = (hrtime(true) - $start) / 1_000_000.0;
            $samples = is_array($state['samples'] ?? null) ? $state['samples'] : [];
            $samples[] = $ms;
            $state['samples'] = array_slice($samples, -self::STORAGE_RING_CAP);
            $state['worst'] = max((float) ($state['worst'] ?? 0.0), $ms);
        } else {
            $state['failures'] = (int) ($state['failures'] ?? 0) + 1;
        }
        Store::set(self::STORAGE_KEY, $state);
    }

    private static function storageLatency(): ?array
    {
        $state = self::storageState();
        if ($state === null) {
            return null;
        }
        $samples = is_array($state['samples'] ?? null) ? $state['samples'] : [];
        if ($samples === []) {
            return ['present' => 1, 'measurable' => 0, 'reasonCode' => 8, 'scopeCode' => self::volumeScope()];
        }
        sort($samples, SORT_NUMERIC);
        $p75 = (float) $samples[(int) floor((count($samples) - 1) * 0.75)];
        $score = Score::scoreMetric($p75, self::STORAGE_LATENCY_GOOD_MS, self::STORAGE_LATENCY_POOR_MS);

        return [
            'score' => $score, 'rating' => Score::getRating($score),
            'p75Ms' => self::r2($p75), 'worstMs' => self::r2((float) ($state['worst'] ?? $p75)),
            'samples' => count($samples), 'scopeCode' => self::volumeScope(),
            'caption' => self::r2($p75) . ' ms p75',
        ];
    }

    private static function storageFailures(): ?array
    {
        $state = self::storageState();
        if ($state === null) {
            return null;
        }
        $attempts = (int) $state['attempts'];
        $failures = (int) ($state['failures'] ?? 0);
        $pct = 100.0 * $failures / $attempts;
        $score = Score::scoreMetric($pct, self::STORAGE_FAILURE_GOOD_PCT, self::STORAGE_FAILURE_POOR_PCT);

        return [
            'score' => $score, 'rating' => Score::getRating($score),
            'failPct' => self::r1($pct), 'failCount' => $failures, 'opCount' => $attempts,
            'scopeCode' => self::volumeScope(), 'caption' => self::r1($pct) . '% failed',
        ];
    }

    private static function storageState(): ?array
    {
        if (RuntimeFlags::stateDir() === null) {
            return null;
        }
        $state = Store::get(self::STORAGE_KEY, []);
        if (!is_array($state) || (int) ($state['attempts'] ?? 0) < self::STORAGE_MIN_OPERATIONS) {
            return null;
        }
        return $state;
    }

    private static function readProcIo(): ?array
    {
        $text = @file_get_contents('/proc/self/io');
        if (!is_string($text)) {
            return null;
        }
        if (preg_match('/^read_bytes:\s*(\d+)$/m', $text, $r) !== 1
            || preg_match('/^write_bytes:\s*(\d+)$/m', $text, $w) !== 1) {
            return null;
        }
        return [(int) $r[1], (int) $w[1]];
    }

    private static function readMajorFaults(): ?int
    {
        $text = @file_get_contents('/proc/self/stat');
        if (!is_string($text)) {
            return null;
        }
        $end = strrpos($text, ')');
        if ($end === false) {
            return null;
        }
        $fields = preg_split('/\s+/', trim(substr($text, $end + 1)));
        return is_array($fields) && isset($fields[9]) && is_numeric($fields[9])
            ? (int) $fields[9] : null;
    }

    private static function push(string $key, array $entry): void
    {
        Store::update($key, static function ($cur) use ($entry): array {
            $ring = is_array($cur) ? $cur : [];
            $ring[] = $entry;
            return array_slice($ring, -self::SAMPLE_RING_CAP);
        }, []);
    }

    /** @return array{0:array,1:array,2:int}|null */
    private static function sameProcessWindow(string $key, int $minimumSpan): ?array
    {
        $ring = Store::get($key, []);
        if (!is_array($ring) || count($ring) < 2) {
            return null;
        }
        $last = $ring[count($ring) - 1];
        $pid = (int) ($last['pid'] ?? 0);
        foreach ($ring as $first) {
            $span = (int) ($last['at'] ?? 0) - (int) ($first['at'] ?? 0);
            if ((int) ($first['pid'] ?? -1) === $pid && $span >= $minimumSpan) {
                return [$first, $last, $span];
            }
        }
        return null;
    }

    private static function volumeScope(): int
    {
        return self::containerised() ? 2 : 3;
    }

    private static function containerised(): bool
    {
        if (self::$containerised !== null) {
            return self::$containerised;
        }
        if (@file_exists('/.dockerenv')) {
            return self::$containerised = true;
        }
        $cgroup = @file_get_contents('/proc/self/cgroup');
        return self::$containerised = is_string($cgroup)
            && preg_match('/docker|kubepods|containerd|lxc/i', $cgroup) === 1;
    }

    private static function r1(float $n): float
    {
        return round($n * 10) / 10;
    }

    private static function r2(float $n): float
    {
        return round($n * 100) / 100;
    }
}