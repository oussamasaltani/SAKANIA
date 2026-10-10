<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * The one PHP definition of a held-open response, plus its numeric-only tally.
 * Header-read failures fail safe to an ordinary request.
 */
final class HeldOpen
{
    private const STORE_KEY = 'held_open';
    private const CONTENT_TYPES = [
        'text/event-stream',
        'multipart/x-mixed-replace',
    ];

    /** @var list<string>|null */
    private static ?array $responseHeadersForTests = null;

    private function __construct()
    {
    }

    /** Case-insensitive media-type match with parameters stripped. Never throws. */
    public static function isHeldOpenContentType(mixed $raw): bool
    {
        return (bool) Safe::run(static function () use ($raw): bool {
            if (!is_string($raw) || $raw === '') {
                return false;
            }
            $mediaType = strtolower(trim(explode(';', $raw, 2)[0]));

            return in_array($mediaType, self::CONTENT_TYPES, true);
        }, false);
    }

    /**
     * Whether the finished response was upgraded or carries a held-open media
     * type. Any unreadable response-header state is an ordinary request.
     */
    public static function isHeldOpenResponse(?int $statusCode = null): bool
    {
        return (bool) Safe::run(static function () use ($statusCode): bool {
            if ($statusCode === 101) {
                return true;
            }
            $headers = self::$responseHeadersForTests;
            if ($headers === null) {
                if (!function_exists('headers_list')) {
                    return false;
                }
                $headers = @headers_list();
            }
            if (!is_array($headers)) {
                return false;
            }
            foreach ($headers as $headerLine) {
                if (!is_string($headerLine) || stripos($headerLine, 'content-type:') !== 0) {
                    continue;
                }
                $value = trim(substr($headerLine, strlen('content-type:')));
                if (self::isHeldOpenContentType($value)) {
                    return true;
                }
            }

            return false;
        }, false);
    }

    /** Count one duration withheld from the latency family. */
    public static function noteExcluded(float $durationMs): void
    {
        if (RuntimeFlags::disabled() || !is_finite($durationMs) || $durationMs < 0) {
            return;
        }
        Safe::fire(static function () use ($durationMs): void {
            $ms = (int) round($durationMs);
            Store::update(self::STORE_KEY, static function ($cur) use ($ms): array {
                $s = is_array($cur) ? $cur : [];

                return [
                    'heldOpenExcluded' => (int) ($s['heldOpenExcluded'] ?? 0) + 1,
                    'heldOpenWorstMs' => max((int) ($s['heldOpenWorstMs'] ?? 0), $ms),
                ];
            }, []);
        });
    }

    /** @return array{heldOpenExcluded:int,heldOpenWorstMs:int} */
    public static function stats(): array
    {
        return Safe::run(static function (): array {
            $s = Store::get(self::STORE_KEY, []);

            return [
                'heldOpenExcluded' => is_array($s) ? (int) ($s['heldOpenExcluded'] ?? 0) : 0,
                'heldOpenWorstMs' => is_array($s) ? (int) ($s['heldOpenWorstMs'] ?? 0) : 0,
            ];
        }, ['heldOpenExcluded' => 0, 'heldOpenWorstMs' => 0]);
    }

    /** Attach the tally only when at least one response was excluded. */
    public static function addFields(array &$axis): void
    {
        $stats = self::stats();
        if ($stats['heldOpenExcluded'] > 0) {
            $axis['heldOpenExcluded'] = $stats['heldOpenExcluded'];
            $axis['heldOpenWorstMs'] = $stats['heldOpenWorstMs'];
        }
    }

    public static function clear(): void
    {
        Safe::fire(static function (): void {
            Store::delete(self::STORE_KEY);
        });
    }

    /** @param list<string>|null $headers */
    public static function setResponseHeadersForTests(?array $headers): void
    {
        self::$responseHeadersForTests = $headers;
    }
}