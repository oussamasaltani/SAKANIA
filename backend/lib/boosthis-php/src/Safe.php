<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * Guest-safety helpers. THE rule for every kit: the host application can never
 * crash, slow down, or change behaviour because Boosthis is installed.
 *
 * Every public entry point in this kit runs its body through Safe::run(), so a
 * bug anywhere inside the kit degrades to "no measurement" instead of an
 * exception in someone else's request. Errors are counted, and surfaced only
 * through the kit's own debug channel — never rethrown, never echoed, never
 * written to the host's output.
 */
final class Safe
{
    private static int $errors = 0;
    /** @var list<string> */
    private static array $recent = [];
    private static ?\Throwable $last = null;
    private const MAX_RECENT = 20;

    private function __construct()
    {
    }

    /**
     * Run $fn, returning $fallback if it throws for any reason.
     *
     * @template T
     * @param callable():T $fn
     * @param T $fallback
     * @return T
     */
    public static function run(callable $fn, mixed $fallback = null): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            self::note($e);

            return $fallback;
        }
    }

    /**
     * Run $fn for its side effects only. Never throws.
     *
     * @param callable():void $fn
     */
    public static function fire(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            self::note($e);
        }
    }

    public static function note(\Throwable $e): void
    {
        self::$errors++;
        self::$last = $e;
        // Class + message only, never a stack trace or interpolated host data:
        // this string can be shown in the bubble.
        $line = get_class($e) . ': ' . $e->getMessage();
        self::$recent[] = strlen($line) > 200 ? substr($line, 0, 200) : $line;
        if (count(self::$recent) > self::MAX_RECENT) {
            array_shift(self::$recent);
        }
        if (RuntimeFlags::debug()) {
            error_log('[boosthis] ' . $line);
        }
    }

    public static function errorCount(): int
    {
        return self::$errors;
    }

    /** @return list<string> */
    public static function recentErrors(): array
    {
        return self::$recent;
    }

    /** The most recently caught error object, if any, for coarse classification. */
    public static function lastError(): ?\Throwable
    {
        return self::$last;
    }

    /** Test seam only. */
    public static function reset(): void
    {
        self::$errors = 0;
        self::$recent = [];
        self::$last = null;
    }
}
