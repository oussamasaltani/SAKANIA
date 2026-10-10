<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * The durable queue for per-request measurements.
 *
 * PHP gets one interpreter per request, so this follows the span queue into the
 * cross-request store. It contains only the three facts the samples endpoint
 * accepts and is drained before transport: an unreachable server must never
 * grow memory in an application we do not own.
 */
final class SampleUploader
{
    public const MAX_BATCH = 100;
    public const MAX_BUFFERED = 500;
    public const MAX_DURATION_MS = 600000;
    public const FLUSH_INTERVAL_MS = 15000;
    public const ALLOWED_RATINGS = [Score::GOOD, Score::NEEDS_WORK, Score::POOR];

    private const KEY = 'sample_uploads';
    private const KEY_LAST_FLUSH = 'sample_upload_last_flush';

    private function __construct()
    {
    }

    /** Buffer the closed three-field wire row. */
    public static function enqueue(string $routeLabel, float $durationMs, string $rating): void
    {
        if (!Uploader::canUpload()) {
            if (!Uploader::canRetainSamplesWhileLocked()) {
                // A kill, disable or consent change is a decision, so it also
                // erases rows buffered by an earlier request.
                self::clear();
                return;
            }
            // The first entitlement answer is still in flight. Keep measuring
            // into the existing bounded queue, but do not relax the send gate.
        }
        Safe::fire(static function () use ($routeLabel, $durationMs, $rating): void {
            $label = RouteInventory::normalizePartName($routeLabel);
            if ($label === null) {
                return;
            }
            if (!in_array($rating, self::ALLOWED_RATINGS, true)) {
                return;
            }
            if (!is_finite($durationMs)) {
                return;
            }
            $sample = [
                'routeLabel' => $label,
                'durationMs' => (int) round(max(0.0, min($durationMs, self::MAX_DURATION_MS))),
                'rating' => $rating,
            ];
            Store::update(self::KEY, static function ($cur) use ($sample): array {
                $list = is_array($cur) ? array_values($cur) : [];
                $list[] = $sample;
                if (count($list) > self::MAX_BUFFERED) {
                    $list = array_slice($list, -self::MAX_BUFFERED);
                }

                return $list;
            }, []);
        });
    }

    public static function count(): int
    {
        $cur = Store::get(self::KEY, []);

        return is_array($cur) ? count($cur) : 0;
    }

    public static function flushDue(): bool
    {
        if (self::count() >= self::MAX_BATCH) {
            return true;
        }
        $last = Store::get(self::KEY_LAST_FLUSH, 0);

        return !is_int($last) || $last <= 0 || Telemetry::nowMs() - $last >= self::FLUSH_INTERVAL_MS;
    }

    /**
     * Capture at most one server-sized batch and remove it in the same locked
     * store update. Invalid legacy rows are discarded rather than poisoning the
     * whole request.
     *
     * @return list<array{routeLabel:string,durationMs:int,rating:string}>
     */
    public static function drainBatch(): array
    {
        $taken = [];
        Store::update(self::KEY, static function ($cur) use (&$taken): array {
            $list = is_array($cur) ? array_values($cur) : [];
            $raw = array_slice($list, 0, self::MAX_BATCH);
            $remaining = array_slice($list, count($raw));
            foreach ($raw as $sample) {
                if (!is_array($sample)) {
                    continue;
                }
                $label = $sample['routeLabel'] ?? null;
                $rating = $sample['rating'] ?? null;
                $duration = $sample['durationMs'] ?? null;
                if (RouteInventory::normalizePartName($label) === null
                    || !is_string($rating) || !in_array($rating, self::ALLOWED_RATINGS, true)
                    || !is_int($duration) || $duration < 0 || $duration > self::MAX_DURATION_MS) {
                    continue;
                }
                $taken[] = [
                    'routeLabel' => $label,
                    'durationMs' => $duration,
                    'rating' => $rating,
                ];
            }

            return $remaining;
        }, []);

        return $taken;
    }

    public static function markFlushAttempted(): void
    {
        Store::set(self::KEY_LAST_FLUSH, Telemetry::nowMs());
    }

    public static function clear(): void
    {
        Store::delete(self::KEY);
        Store::delete(self::KEY_LAST_FLUSH);
    }
}