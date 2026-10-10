<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * The measurement ring.
 *
 * On every other runtime this is an in-process array. PHP has no process to
 * keep it in, so the ring lives in the cross-request store and each request
 * appends its own leg. Entries are stored as compact triples — [route,
 * durationMs, atMs] — because this file is read and rewritten on every request
 * and a verbose shape would cost real I/O.
 *
 * Nothing here is user data: a route label has already passed the route guard,
 * a duration is a number, and a timestamp is a clock reading.
 */
final class Samples
{
    private const KEY = 'samples';
    /** Hard cap. A busy app must never grow this file without bound. */
    public const MAX_SAMPLES = 500;
    /** Samples older than this are dropped on write — the meters are about now. */
    public const MAX_AGE_MS = 3600000;

    private function __construct()
    {
    }

    /** Record one completed measurement. Silently ignores an unsafe label. */
    public static function record(string $route, float $durationMs, ?int $atMs = null): void
    {
        if (RuntimeFlags::disabled()) {
            return;
        }
        Safe::fire(static function () use ($route, $durationMs, $atMs): void {
            $route = RouteInventory::normalizePartName($route);
            if ($route === null) {
                return;
            }
            $at = $atMs ?? Telemetry::nowMs();
            $d = (int) round(max(0.0, $durationMs));
            $rating = Score::getDurationRating((float) $d);
            Store::update(self::KEY, static function ($cur) use ($route, $d, $at) {
                $list = is_array($cur) ? $cur : [];
                $list[] = [$route, $d, $at];
                $cutoff = $at - self::MAX_AGE_MS;
                $list = array_values(array_filter(
                    $list,
                    static fn ($s) => is_array($s) && count($s) === 3 && (int) $s[2] >= $cutoff
                ));
                if (count($list) > self::MAX_SAMPLES) {
                    $list = array_slice($list, -self::MAX_SAMPLES);
                }

                return $list;
            }, []);
            // Feed the per-request ingest from the single record boundary. This
            // reuses the exact guarded label, rounded duration and rating that
            // were just committed to the local measurement ring.
            SampleUploader::enqueue($route, (float) $d, $rating);
        });
    }

    /**
     * Oldest-first list of samples as ['route'=>string,'durationMs'=>int,'at'=>int].
     *
     * @return list<array{route:string,durationMs:int,at:int}>
     */
    public static function all(): array
    {
        $raw = Store::get(self::KEY, []);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $s) {
            if (is_array($s) && count($s) === 3) {
                $out[] = [
                    'route' => (string) $s[0],
                    'durationMs' => (int) $s[1],
                    'at' => (int) $s[2],
                ];
            }
        }

        return $out;
    }

    /** @return list<int> durations only, oldest-first. */
    public static function durations(): array
    {
        return array_map(static fn ($s) => $s['durationMs'], self::all());
    }

    public static function count(): int
    {
        return count(self::all());
    }

    public static function clear(): void
    {
        Store::delete(self::KEY);
    }

    /**
     * Percentile over a list of numbers, nearest-rank on a sorted copy.
     * Shared by every meter so one definition of p75/p95/p99 exists.
     *
     * @param list<int|float> $values
     */
    public static function percentile(array $values, float $p): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $idx = (int) ceil($p * count($values)) - 1;
        if ($idx < 0) {
            $idx = 0;
        }
        if ($idx >= count($values)) {
            $idx = count($values) - 1;
        }

        return (float) $values[$idx];
    }
}
