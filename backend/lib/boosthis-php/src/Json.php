<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * JSON helpers with the kit's encoding contract baked in.
 *
 * Two things matter for cross-runtime parity:
 *  - an empty axes/rows container must serialize as `{}` / `[]` exactly as the
 *    other kits do, so a PHP array is never guessed at;
 *  - invalid UTF-8 anywhere in a payload must not blow up the host — it is
 *    substituted, and the guard still runs over the original values.
 */
final class Json
{
    private function __construct()
    {
    }

    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE;

    /** Encode, returning null (never throwing) when the value cannot be encoded. */
    public static function encode(mixed $value): ?string
    {
        try {
            $out = json_encode($value, self::FLAGS | JSON_THROW_ON_ERROR);

            return $out === false ? null : $out;
        } catch (\Throwable $e) {
            Safe::note($e);

            return null;
        }
    }

    /**
     * Decode to an associative array, returning null on any error.
     *
     * @return array<mixed>|null
     */
    public static function decode(?string $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            $out = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);

            return is_array($out) ? $out : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Force a PHP array to encode as a JSON OBJECT even when empty.
     * The wire contract says `axes` is a map — an empty PHP array would
     * otherwise serialize as `[]` and fail server validation.
     *
     * @param array<string,mixed> $map
     */
    public static function objectOrEmpty(array $map): object
    {
        return (object) $map;
    }
}
