<?php

declare(strict_types=1);

namespace Boosthis;

/** Label-free background-work collector for CLI workers and schedulers. */
final class JobWork
{
    private const KEY = 'reading:backgroundWork';
    private const MAX_NAMES = 20;
    private const RING = 500;
    private const GAP_RING = 12;
    private const MAX_ACTIVE = 512;

    /** @var array<int,array{name:string,started:float,startedWall:int,attempt:int,hosted:bool}> */
    private static array $active = [];
    private static int $nextToken = 1;
    private static bool $laravelAttached = false;

    private function __construct()
    {
    }

    /** @param array{queuedAtMs?:int|float,attempt?:int,system?:string,hosted?:bool} $options */
    public static function begin(string $name, array $options = []): int
    {
        return Safe::run(static function () use ($name, $options): int {
            if (RuntimeFlags::disabled()) {
                return 0;
            }
            $clean = self::cleanName($name);
            if ($clean === null) {
                return 0;
            }
            $now = Telemetry::nowMs();
            $token = self::$nextToken++;
            $attempt = max(1, (int) ($options['attempt'] ?? 1));
            $system = isset($options['system']) && is_string($options['system']) && $options['system'] !== ''
                ? $options['system'] : null;
            $hosted = ($options['hosted'] ?? false) === true;

            Store::update(self::KEY, static function ($cur) use (
                $clean, $now, $attempt, $system, $hosted, $options
            ): array {
                $s = self::state($cur);
                $key = self::nameKey($s, $clean);
                $job = is_array($s['jobs'][$key] ?? null) ? $s['jobs'][$key] : self::job();
                if ((int) $job['active'] > 0) {
                    $s['overlaps']++;
                }
                if ((int) $s['activeTotal'] < self::MAX_ACTIVE) {
                    $job['active']++;
                    $s['activeTotal']++;
                }
                if ($job['lastStartMs'] !== null) {
                    $gap = $now - (int) $job['lastStartMs'];
                    if ($gap > 0) {
                        $cadence = self::cadence($job['gaps']);
                        if ($cadence !== null && $gap > $cadence * 1.5) {
                            $job['missed'] += max(0, (int) round($gap / $cadence) - 1);
                        }
                        $job['gaps'][] = $gap;
                        $job['gaps'] = array_values(array_slice($job['gaps'], -self::GAP_RING));
                    }
                }
                $job['lastStartMs'] = $now;
                $s['jobs'][$key] = $job;
                if ($system !== null) {
                    $s['attached'][$system] = 1;
                    unset($s['unattached'][$system]);
                } else {
                    $s['manualRuns']++;
                }
                if ($hosted) {
                    $s['hostedRuns']++;
                }
                if (isset($options['queuedAtMs']) && is_numeric($options['queuedAtMs'])) {
                    $wait = $now - (float) $options['queuedAtMs'];
                    if ($wait >= 0) {
                        $s['waits'][] = $wait;
                        $s['waits'] = array_values(array_slice($s['waits'], -self::RING));
                        $s['waitRuns']++;
                    }
                }
                if ($attempt > 1) {
                    $s['retried']++;
                    $s['retryWorst'] = max($s['retryWorst'], $attempt);
                }
                return $s;
            }, self::state(null));

            self::$active[$token] = [
                'name' => $clean, 'started' => microtime(true), 'startedWall' => $now,
                'attempt' => $attempt, 'hosted' => $hosted,
            ];
            return $token;
        }, 0) ?? 0;
    }

    public static function finish(int $token, bool $failed = false): void
    {
        Safe::fire(static function () use ($token, $failed): void {
            $run = self::$active[$token] ?? null;
            if ($run === null) {
                return;
            }
            unset(self::$active[$token]);
            $duration = max(0, (microtime(true) - $run['started']) * 1000);
            Store::update(self::KEY, static function ($cur) use ($run, $failed, $duration): array {
                $s = self::state($cur);
                $key = array_key_exists($run['name'], $s['jobs']) ? $run['name'] : 'other';
                $job = is_array($s['jobs'][$key] ?? null) ? $s['jobs'][$key] : self::job();
                $job['runs']++;
                $job['failed'] += $failed ? 1 : 0;
                $job['worstMs'] = max($job['worstMs'], $duration);
                if ($job['active'] > 0) {
                    $job['active']--;
                    $s['activeTotal'] = max(0, $s['activeTotal'] - 1);
                }
                $s['jobs'][$key] = $job;
                $s['durations'][] = $duration;
                $s['durations'] = array_values(array_slice($s['durations'], -self::RING));
                return $s;
            }, self::state(null));
        });
    }

    public static function noteUnattached(string $system): void
    {
        Safe::fire(static function () use ($system): void {
            if ($system === '') {
                return;
            }
            // System identifiers are never uploaded; only set cardinality is.
            Store::update(self::KEY, static function ($cur) use ($system): array {
                $s = self::state($cur);
                if (!isset($s['attached'][$system]) && count($s['unattached']) < 20) {
                    $s['unattached'][$system] = 1;
                }
                return $s;
            }, self::state(null));
        });
    }

    /**
     * Attach to Laravel's queue event dispatcher without introducing a Laravel
     * dependency. The three event class names are strings until Laravel calls.
     */
    public static function attachLaravelQueue(object $events): void
    {
        Safe::fire(static function () use ($events): void {
            if (self::$laravelAttached || !method_exists($events, 'listen')) {
                return;
            }
            self::$laravelAttached = true;
            $tokens = [];
            $events->listen('Illuminate\\Queue\\Events\\JobProcessing', static function ($event) use (&$tokens): void {
                Safe::fire(static function () use ($event, &$tokens): void {
                    $job = is_object($event) && isset($event->job) ? $event->job : null;
                    if (!is_object($job)) {
                        return;
                    }
                    $id = spl_object_id($job);
                    $name = method_exists($job, 'resolveName') ? (string) $job->resolveName() : 'job';
                    $attempt = method_exists($job, 'attempts') ? (int) $job->attempts() : 1;
                    $tokens[$id] = self::begin($name, ['attempt' => $attempt, 'system' => 'laravel']);
                });
            });
            $close = static function ($event, bool $failed) use (&$tokens): void {
                Safe::fire(static function () use ($event, $failed, &$tokens): void {
                    $job = is_object($event) && isset($event->job) ? $event->job : null;
                    if (!is_object($job)) {
                        return;
                    }
                    $id = spl_object_id($job);
                    self::finish((int) ($tokens[$id] ?? 0), $failed);
                    unset($tokens[$id]);
                });
            };
            $events->listen('Illuminate\\Queue\\Events\\JobProcessed',
                static fn ($event) => $close($event, false));
            $events->listen('Illuminate\\Queue\\Events\\JobExceptionOccurred',
                static fn ($event) => $close($event, true));
        });
    }

    /** @return array<string,mixed>|null */
    public static function read(?int $now = null): ?array
    {
        return Safe::run(static function () use ($now): ?array {
            $s = self::state(Store::get(self::KEY, null));
            $runs = $failed = $worst = $missed = $recurring = 0;
            $at = $now ?? Telemetry::nowMs();
            foreach ($s['jobs'] as $job) {
                $runs += (int) $job['runs'];
                $failed += (int) $job['failed'];
                $worst = max($worst, (float) $job['worstMs']);
                $cadence = self::cadence($job['gaps']);
                if ($cadence !== null) {
                    $recurring++;
                    $missed += (int) $job['missed'];
                    if ($job['lastStartMs'] !== null && (int) $job['active'] === 0) {
                        $quiet = $at - (int) $job['lastStartMs'];
                        if ($quiet > $cadence * 1.5) {
                            $missed += max(0, (int) round($quiet / $cadence) - 1);
                        }
                    }
                }
            }
            $unattached = count($s['unattached']);
            if ($runs === 0 && $unattached === 0) {
                return null;
            }
            $base = [
                'runs' => $runs, 'jobNames' => count($s['jobs']),
                'otherNames' => count($s['folded']), 'failed' => $failed,
                'retried' => (int) $s['retried'], 'retryWorst' => max(1, (int) $s['retryWorst']),
                'overlaps' => (int) $s['overlaps'], 'waitRuns' => (int) $s['waitRuns'],
                'recurring' => $recurring, 'hostedRuns' => (int) $s['hostedRuns'],
                'manualRuns' => (int) $s['manualRuns'], 'attachedSystems' => count($s['attached']),
                'unattachedSystems' => $unattached,
            ];
            if ($runs < 5) {
                return ['score' => null, 'rating' => 'pending', 'measurable' => 0,
                    'failPct' => 0, 'p95Ms' => 0, 'worstMs' => 0,
                    'waitP95Ms' => null, 'missed' => null] + $base;
            }
            $failPct = round($failed / $runs * 1000) / 10.0;
            $waitP95 = $s['waitRuns'] > 0 ? self::percentile($s['waits'], 0.95) : null;
            $parts = [
                Score::scoreMetric($failPct, 1, 20),
                Score::scoreMetric((float) $s['overlaps'], 0, 5),
            ];
            if ($recurring > 0) {
                $parts[] = Score::scoreMetric($missed, 0, 3);
            }
            if ($waitP95 !== null) {
                $parts[] = Score::scoreMetric($waitP95, 1000, 60000);
            }
            $score = min($parts);
            return ['score' => $score, 'rating' => Score::getRating($score), 'measurable' => 1,
                'failPct' => $failPct, 'p95Ms' => self::percentile($s['durations'], 0.95),
                'worstMs' => (int) round($worst), 'waitP95Ms' => $waitP95,
                'missed' => $recurring > 0 ? $missed : null] + $base;
        }, null);
    }

    public static function clear(): void
    {
        self::$active = [];
        self::$nextToken = 1;
        self::$laravelAttached = false;
        Store::delete(self::KEY);
    }

    private static function cleanName(string $name): ?string
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 60 || Pii::checkRouteLabel($name) !== null) {
            return null;
        }
        return $name;
    }

    /** @param mixed $cur @return array<string,mixed> */
    private static function state($cur): array
    {
        return is_array($cur) ? array_replace([
            'jobs' => [], 'folded' => [], 'durations' => [], 'waits' => [],
            'waitRuns' => 0, 'retried' => 0, 'retryWorst' => 1, 'overlaps' => 0,
            'hostedRuns' => 0, 'manualRuns' => 0, 'attached' => [], 'unattached' => [],
            'activeTotal' => 0,
        ], $cur) : [
            'jobs' => [], 'folded' => [], 'durations' => [], 'waits' => [],
            'waitRuns' => 0, 'retried' => 0, 'retryWorst' => 1, 'overlaps' => 0,
            'hostedRuns' => 0, 'manualRuns' => 0, 'attached' => [], 'unattached' => [],
            'activeTotal' => 0,
        ];
    }

    /** @param array<string,mixed> $s */
    private static function nameKey(array &$s, string $name): string
    {
        if (isset($s['jobs'][$name])) {
            return $name;
        }
        if (count($s['jobs']) < self::MAX_NAMES) {
            $s['jobs'][$name] = self::job();
            return $name;
        }
        if (count($s['folded']) < 500) {
            $s['folded'][$name] = 1;
        }
        $s['jobs']['other'] ??= self::job();
        return 'other';
    }

    /** @return array<string,mixed> */
    private static function job(): array
    {
        return ['runs' => 0, 'failed' => 0, 'worstMs' => 0.0, 'active' => 0,
            'lastStartMs' => null, 'gaps' => [], 'missed' => 0];
    }

    /** @param list<int|float> $gaps */
    private static function cadence(array $gaps): ?float
    {
        if (count($gaps) < 4) {
            return null;
        }
        sort($gaps);
        $n = count($gaps);
        $m = $n % 2 === 0 ? ($gaps[$n / 2 - 1] + $gaps[$n / 2]) / 2 : $gaps[intdiv($n, 2)];
        return $m > 0 && ($gaps[$n - 1] - $gaps[0]) / $m <= 0.25 ? $m : null;
    }

    /** @param list<int|float> $values */
    private static function percentile(array $values, float $p): int
    {
        if ($values === []) {
            return 0;
        }
        sort($values);
        return (int) round($values[max(0, (int) ceil($p * count($values)) - 1)]);
    }
}