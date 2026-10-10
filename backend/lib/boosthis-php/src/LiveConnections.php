<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * Explicit long-lived connection boundary for application-owned PHP servers.
 * Only folded endpoint codes, counts, durations, and bounded cadence gaps are
 * retained. PHP-FPM correctly leaves this axis absent because it never calls
 * this boundary.
 */
final class LiveConnections
{
    private const KEY = 'meter-live-connections-v1';
    private const MAX_TRACKED = 500;
    private const MAX_GAPS = 64;
    private const MAX_REOPENS = 32;
    private const QUIET_MS = 30000;
    private const MIN_GAPS = 5;
    private const DEAD_MULTIPLE = 6;
    private const RECONNECT_MS = 60000;
    private const STORM_MS = 60000;
    private const STORM_THRESHOLD = 5;
    private const STORM_BACKOFF_MS = 2000;
    private const MIN_WINDOW_MS = 30000;
    private const RATE_WINDOW_MS = 300000;
    private const LEAK_AGE_MS = 300000;
    private const LEAK_MIN = 8;

    /** @var null|callable():int */
    private static $clock = null;

    private function __construct()
    {
    }

    /** @param array{flowMeasurable?:bool} $options */
    public static function open(string $endpoint, array $options = []): string
    {
        $token = bin2hex(random_bytes(12));
        $now = self::now();
        $endpointCode = self::fold($endpoint);
        $flow = ($options['flowMeasurable'] ?? false) === true;
        Store::update(self::KEY, static function ($raw) use ($token, $now, $endpointCode, $flow): array {
            $s = self::state($raw);
            if ($s['firstSeenAt'] === 0) {
                $s['firstSeenAt'] = $now;
            }
            $s['opened']++;
            if (!$flow) {
                $s['anyOpaque'] = true;
            }
            $key = (string) $endpointCode;
            if (isset($s['lastEnd'][$key]) && $now - $s['lastEnd'][$key] <= self::RECONNECT_MS) {
                $s['reconnects']++;
                $stamps = $s['reopens'][$key] ?? [];
                $stamps[] = $now;
                $stamps = array_values(array_filter(
                    $stamps,
                    static fn ($at): bool => is_int($at) && $at >= $now - self::STORM_MS
                ));
                if (count($stamps) > self::MAX_REOPENS) {
                    $stamps = array_slice($stamps, -self::MAX_REOPENS);
                }
                $s['reopens'][$key] = $stamps;
                if (count($stamps) >= self::STORM_THRESHOLD
                    && self::medianGaps($stamps) < self::STORM_BACKOFF_MS) {
                    $s['stormPeak'] = max($s['stormPeak'], count($stamps));
                }
                if (count($s['reopens']) > self::MAX_TRACKED) {
                    $oldest = array_key_first($s['reopens']);
                    if ($oldest !== null) {
                        unset($s['reopens'][$oldest]);
                    }
                }
            }
            if (count($s['connections']) >= self::MAX_TRACKED) {
                foreach ($s['connections'] as $oldToken => $old) {
                    if (($old['closedAt'] ?? null) !== null) {
                        unset($s['connections'][$oldToken]);
                        break;
                    }
                }
            }
            if (count($s['connections']) < self::MAX_TRACKED) {
                $s['connections'][$token] = [
                    'endpoint' => $endpointCode, 'openedAt' => $now, 'lastSeenAt' => $now,
                    'gaps' => [], 'messages' => 0, 'closedAt' => null,
                    'flow' => $flow, 'backlog' => 0,
                ];
            }
            $s['peakOpen'] = max($s['peakOpen'], self::openCount($s));
            return $s;
        }, self::state(null));
        return $token;
    }

    public static function activity(string $token, int $messages = 1, int $backlog = 0): void
    {
        $now = self::now();
        Store::update(self::KEY, static function ($raw) use ($token, $messages, $backlog, $now): array {
            $s = self::state($raw);
            if (!isset($s['connections'][$token]) || $s['connections'][$token]['closedAt'] !== null) {
                return $s;
            }
            $c = &$s['connections'][$token];
            $gap = $now - $c['lastSeenAt'];
            if ($gap > 0) {
                $c['gaps'][] = $gap;
                if (count($c['gaps']) > self::MAX_GAPS) {
                    $c['gaps'] = array_slice($c['gaps'], -self::MAX_GAPS);
                }
            }
            $c['lastSeenAt'] = $now;
            $c['backlog'] = max(0, $backlog);
            if ($c['flow']) {
                $add = max(0, $messages);
                $c['messages'] += $add;
                $s['messages'] += $add;
            }
            unset($c);
            return $s;
        }, self::state(null));
    }

    public static function close(string $token, bool $clean): void
    {
        $now = self::now();
        Store::update(self::KEY, static function ($raw) use ($token, $clean, $now): array {
            $s = self::state($raw);
            if (!isset($s['connections'][$token]) || $s['connections'][$token]['closedAt'] !== null) {
                return $s;
            }
            $c = &$s['connections'][$token];
            $c['closedAt'] = $now;
            $s['closed']++;
            if (!$clean) {
                $s['drops']++;
            }
            $s['lastEnd'][(string) $c['endpoint']] = $now;
            unset($c);
            if (count($s['lastEnd']) > self::MAX_TRACKED) {
                asort($s['lastEnd'], SORT_NUMERIC);
                $s['lastEnd'] = array_slice($s['lastEnd'], -self::MAX_TRACKED, null, true);
            }
            return $s;
        }, self::state(null));
    }

    /** @return array<string,mixed>|null */
    public static function read(): ?array
    {
        $s = self::state(Store::get(self::KEY));
        if ($s['firstSeenAt'] === 0) {
            return null;
        }
        $now = self::now();
        $elapsed = max(0, $now - $s['firstSeenAt']);
        if ($elapsed < self::MIN_WINDOW_MS) {
            return null;
        }
        $open = $quiet = $stalled = $undecided = $neverClosed = $backlog = 0;
        $longest = 0;
        $lives = [];
        foreach ($s['connections'] as $c) {
            $end = $c['closedAt'] ?? $now;
            $life = max(0, $end - $c['openedAt']);
            $longest = max($longest, $life);
            if ($c['closedAt'] !== null) {
                $lives[] = $life;
                continue;
            }
            $open++;
            $backlog += max(0, (int) $c['backlog']);
            if ($life >= self::LEAK_AGE_MS) {
                $neverClosed++;
            }
            $silence = $now - $c['lastSeenAt'];
            if ($silence < self::QUIET_MS) {
                continue;
            }
            $quiet++;
            if (count($c['gaps']) < self::MIN_GAPS) {
                $undecided++;
                continue;
            }
            $typical = self::median($c['gaps']);
            if ($typical <= 0) {
                $undecided++;
            } elseif ($silence > $typical * self::DEAD_MULTIPLE) {
                $stalled++;
            }
        }
        if ($open < self::LEAK_MIN || $neverClosed < self::LEAK_MIN) {
            $neverClosed = 0;
        }
        $storm = $s['stormPeak'] >= self::STORM_THRESHOLD ? $s['stormPeak'] : 0;
        $dropsPerHour = $elapsed >= self::RATE_WINDOW_MS
            ? round($s['drops'] / ($elapsed / 3600000), 1)
            : null;
        $score = $dropsPerHour === null ? null : (int) round(
            0.35 * Score::scoreMetric($dropsPerHour, 0.5, 12)
            + 0.30 * ($stalled === 0 ? 100 : ($stalled === 1 ? 40 : 0))
            + 0.20 * ($storm === 0 ? 100 : 0)
            + 0.15 * ($neverClosed === 0 ? 100 : 0)
        );
        $namedDrop = $dropsPerHour !== null && $s['drops'] > 0 && $dropsPerHour > 0.5;
        $namesProblem = $storm > 0 || $stalled > 0 || $neverClosed > 0 || $namedDrop;
        $rating = $score === null ? 'pending' : Score::getRating($score);
        if ($namesProblem && $rating === 'good') {
            $rating = 'needs-work';
        }
        $axis = [
            'score' => $score, 'rating' => $rating,
            'caption' => self::caption($open, $s['drops'], $namedDrop, $dropsPerHour === null, $stalled, $undecided, $storm, $neverClosed),
            'open' => $open, 'peakOpen' => $s['peakOpen'], 'opened' => $s['opened'],
            'closed' => $s['closed'], 'drops' => $s['drops'], 'reconnects' => $s['reconnects'],
            'reconnectsPerHour' => $elapsed >= self::RATE_WINDOW_MS
                ? round($s['reconnects'] / ($elapsed / 3600000), 1) : null,
            'medianLifeMs' => (int) round(self::median($lives)), 'longestMs' => $longest,
            'stormCount' => $storm, 'quiet' => $quiet, 'stalled' => $stalled,
            'undecided' => $undecided, 'neverClosed' => $neverClosed, 'perScreen' => 0,
            'backlog' => $backlog, 'windowMin' => floor($elapsed / 1000) / 60,
            'measurable' => $score === null ? 0 : 1, 'flowMeasurable' => $s['anyOpaque'] ? 0 : 1,
        ];
        if (!$s['anyOpaque']) {
            $axis['msgsPerMin'] = $elapsed >= 5000
                ? round($s['messages'] / ($elapsed / 60000), 1) : null;
        }
        return $axis;
    }

    public static function clear(): void
    {
        Store::delete(self::KEY);
        self::$clock = null;
    }

    /** @internal */
    public static function setClockForTests(?callable $clock): void
    {
        self::$clock = $clock;
    }

    private static function now(): int
    {
        return self::$clock !== null ? (int) (self::$clock)() : Telemetry::nowMs();
    }

    /** @return array<string,mixed> */
    private static function state($raw): array
    {
        $base = [
            'firstSeenAt' => 0, 'connections' => [], 'lastEnd' => [], 'reopens' => [],
            'opened' => 0, 'closed' => 0, 'drops' => 0, 'reconnects' => 0,
            'messages' => 0, 'peakOpen' => 0, 'stormPeak' => 0, 'anyOpaque' => false,
        ];
        return is_array($raw) ? array_replace($base, $raw) : $base;
    }

    /** @param array<string,mixed> $s */
    private static function openCount(array $s): int
    {
        return count(array_filter(
            $s['connections'],
            static fn (array $c): bool => $c['closedAt'] === null
        ));
    }

    /** @param list<int|float> $values */
    private static function median(array $values): float
    {
        if ($values === []) {
            return 0;
        }
        sort($values, SORT_NUMERIC);
        $middle = intdiv(count($values), 2);
        return count($values) % 2 === 1
            ? (float) $values[$middle]
            : ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;
    }

    /** @param list<int> $ascending */
    private static function medianGaps(array $ascending): float
    {
        $gaps = [];
        for ($i = 1; $i < count($ascending); $i++) {
            $gaps[] = $ascending[$i] - $ascending[$i - 1];
        }
        return self::median($gaps);
    }

    private static function fold(string $value): int
    {
        return (int) sprintf('%u', crc32($value));
    }

    private static function caption(
        int $open,
        int $drops,
        bool $dropsNamed,
        bool $dropsUnjudged,
        int $stalled,
        int $undecided,
        int $storm,
        int $neverClosed
    ): string {
        if ($storm > 0) return "$storm reconnects in a minute with no widening gap — add back-off";
        if ($stalled > 0) return "$stalled open but silent past its own rhythm — likely dead";
        if ($neverClosed > 0) return "$neverClosed connections open for minutes and never closed";
        if ($undecided > 0) return "$undecided quiet, and nothing arrives regularly enough to tell dead from resting";
        if ($dropsNamed) return "$open open · $drops dropped without a goodbye";
        if ($dropsUnjudged && $drops > 0) return "$open open · $drops dropped — too early to say whether that is a normal rate";
        if ($drops > 0) return "$open open · $drops brief drops, within the normal rate";
        return "$open open · none dropped";
    }
}