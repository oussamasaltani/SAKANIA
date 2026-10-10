<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * The {@code swallowedErrors} additive meter axis (PHP) — the "near-miss rate":
 * how often the host app hit a warning/notice/deprecation and quietly carried on
 * without crashing. Additive and display-only: it NEVER feeds the Speed score.
 *
 * WHY PHP CAN MEASURE THIS HONESTLY WHERE JAVA CANNOT. The Java kit reports
 * {@code {measurable:0}} because java.util.logging has no global record factory
 * and its only observation points would CHANGE the host's own logging. PHP is
 * different: it has one process-wide error-handler chain that a guest can join
 * WITHOUT altering behaviour, provided the handler always delegates to the
 * previous one and honours its return value. That is exactly a near-miss signal:
 *   - Errors the host suppressed with the {@code @} operator, and
 *   - Errors a handler returned {@code true} for (i.e. "handled, keep going"),
 * are precisely the ones the app logged-or-swallowed and survived. PHP surfaces
 * whether an error was suppressed via {@code error_reporting() === 0} (or,
 * on PHP 8, the {@code @}-operator bit) at handler time, so this axis can tell a
 * genuinely-swallowed warning from one the host is about to act on.
 *
 * GUEST-SAFETY (the whole point of this file). The chained handler:
 *   - Stores the PREVIOUS handler at install and ALWAYS calls it, returning its
 *     result verbatim, so the host's own error handling is byte-identical. If
 *     there was no previous handler, it returns {@code false} so PHP's built-in
 *     handler runs exactly as it would have.
 *   - Never emits output, never throws (a throw from an error handler would turn
 *     a warning into a fatal), never logs the error text.
 *   - Excludes the kit's own namespace so a Boosthis warning is never counted as
 *     the host's near miss.
 * A separate shutdown function reads {@code error_get_last()} to count a
 * fatal-ish final error (the app did NOT survive that one, but it is still the
 * host's own signal); it too only ever increments a counter.
 *
 * PRIVACY. The error MESSAGE and FILE are never stored — only counts and the PHP
 * error LEVEL constants (E_WARNING etc.), which are integers. Nothing that could
 * carry a user value ever leaves classification scope.
 *
 * GATES (shared cross-runtime contract). A 10-minute minimum observation window
 * before the axis reports at all (omitted → warming); a 60-minute trailing
 * window; bands 1/hr good, 60/hr poor; a bounded ring capped at RING_CAP.
 *
 * FIELD NAMES. Every emitted field name — {@code score, rating, perHour,
 * windowMin, suppressedCount, handledCount, caption} — is clean under
 * {@code Pii::findDeniedFragment()} (no denied fragment; "count" is not
 * denylisted).
 */
final class SwallowedErrors
{
    private function __construct()
    {
    }

    // ── Gates (shared cross-runtime contract) ────────────────────────────────

    /**
     * Minimum observation window before the axis reports (10 min). The task
     * specifies a 10-minute floor for this PHP axis so a short-lived deployment
     * never publishes a "clean" bill for time it barely watched.
     */
    public const MIN_WINDOW_MS = 10 * 60 * 1000;
    /** Trailing window the counts are computed over (60 min). */
    public const TRAILING_WINDOW_MS = 60 * 60 * 1000;
    /** Bounded ring of recent events so persisted state never grows unbounded. */
    public const RING_CAP = 400;

    /** Bands (per hour): <= 1/hr scores 100, >= 60/hr scores 0, linear between. */
    public const GOOD_PER_HOUR = 1.0;
    public const POOR_PER_HOUR = 60.0;

    // ── Cross-request state keys (PHP has no process to hold these) ───────────

    /** When observation began (epoch ms) — the 10-min gate measures from here. */
    private const KEY_SINCE = 'swallowed.since';
    /** Bounded ring of events: list of ['at'=>int,'level'=>int,'suppressed'=>bool]. */
    private const KEY_RING = 'swallowed.ring';

    /** Guard so we install our chained handler at most once per process. */
    private static bool $installed = false;
    /**
     * The handler that was in place before ours, captured at install. Always
     * delegated to so the host's error handling is unchanged. {@code null} means
     * there was no previous handler → fall back to PHP's default (return false).
     *
     * @var callable|null
     */
    private static $previous = null;
    /** Reentrancy guard: never count an error raised inside our own handler. */
    private static bool $inHandler = false;

    /**
     * Install the chained error handler and the fatal-scan shutdown function.
     * Idempotent and safe to call on every request; the second call is a cheap
     * no-op. No-op entirely under the kill-switch. Never throws.
     *
     * Call this from the kit's enable path (the same place other observers are
     * wired), so the observation window starts at real first activity.
     */
    public static function install(): void
    {
        if (self::$installed || RuntimeFlags::disabled()) {
            return;
        }
        Safe::fire(static function (): void {
            self::$installed = true;
            self::ensureObserving();
            // Chain: set_error_handler returns the previous handler, which we
            // store and ALWAYS delegate to so host behaviour is byte-identical.
            self::$previous = set_error_handler([self::class, 'handle']);
            register_shutdown_function([self::class, 'onShutdown']);
        });
    }

    /**
     * Begin the observation window if it has not started (idempotent). Measured
     * from real first activity, never from process start.
     */
    private static function ensureObserving(): void
    {
        $since = Store::get(self::KEY_SINCE, 0);
        if (!is_int($since) || $since <= 0) {
            Store::set(self::KEY_SINCE, Telemetry::nowMs());
        }
    }

    /**
     * The chained error handler. Counts the near miss, then ALWAYS delegates to
     * the previous handler and returns its result so the host's own error
     * handling — suppression, custom formatting, whatever it does — is
     * unchanged. Never throws, never outputs.
     *
     * @param int    $level   one of the E_* constants
     * @param string $message the error text — READ for delegation only, never stored
     * @param string $file    the file — never stored
     * @param int    $line    the line — never stored
     */
    public static function handle(int $level, string $message, string $file = '', int $line = 0): bool
    {
        // Observe on a fully self-guarded side path; a bug here must never
        // change whether the host's error is handled.
        if (!self::$inHandler) {
            self::$inHandler = true;
            Safe::fire(static function () use ($level, $message, $file): void {
                if (RuntimeFlags::disabled()) {
                    return;
                }
                // Exclude the kit's own errors: a Boosthis warning is not the
                // host's near miss. The file path is used ONLY for this check
                // and is never stored.
                if ($file !== '' && str_contains($file, DIRECTORY_SEPARATOR . 'boosthis-php' . DIRECTORY_SEPARATOR)) {
                    return;
                }
                // "Suppressed" = the host silenced this error, either via the @
                // operator (error_reporting() drops to 0 for the duration) or by
                // masking this level out of error_reporting entirely. That is
                // the genuine "swallowed and survived" signal.
                $reporting = error_reporting();
                $suppressed = $reporting === 0 || ($reporting & $level) === 0;
                self::record($level, $suppressed);
                // Single hook, shared with leakWatch: the SAME message stream
                // feeds the leak classifier (piggyback, never a second handler).
                // The message is used only inside observeMessage and never
                // stored beyond a one-word category tag.
                LeakWatch::observeMessage($message);
            });
            self::$inHandler = false;
        }

        // ALWAYS delegate. If a handler existed before us, its return value is
        // authoritative (true = handled, suppress PHP's internal handler).
        if (self::$previous !== null) {
            return (bool) call_user_func(self::$previous, $level, $message, $file, $line);
        }

        // No previous handler → returning false lets PHP's built-in handler run
        // exactly as it would have without us.
        return false;
    }

    /**
     * Shutdown scan: a fatal-ish final error (E_ERROR / E_PARSE / E_CORE_ERROR /
     * E_COMPILE_ERROR / E_USER_ERROR) that ended the request bypasses the error
     * handler, so it is counted here. It is NOT a survived near miss, but it is
     * still the host's own error signal; it is recorded as not-suppressed. Only
     * the level is ever stored. Never throws.
     */
    public static function onShutdown(): void
    {
        Safe::fire(static function (): void {
            if (RuntimeFlags::disabled()) {
                return;
            }
            $last = error_get_last();
            if ($last === null) {
                return;
            }
            $level = (int) ($last['type'] ?? 0);
            $fatalMask = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
            if (($level & $fatalMask) === 0) {
                return;
            }
            $file = (string) ($last['file'] ?? '');
            if ($file !== '' && str_contains($file, DIRECTORY_SEPARATOR . 'boosthis-php' . DIRECTORY_SEPARATOR)) {
                return;
            }
            self::record($level, false);
        });
    }

    /**
     * File one observation into the bounded, cross-request ring. Stores the
     * timestamp, the integer PHP error level, and whether it was suppressed —
     * never the message or file.
     */
    private static function record(int $level, bool $suppressed): void
    {
        Store::update(self::KEY_RING, static function ($cur) use ($level, $suppressed): array {
            $ring = is_array($cur) ? $cur : [];
            $ring[] = ['at' => Telemetry::nowMs(), 'level' => $level, 'suppressed' => $suppressed];
            $overflow = count($ring) - self::RING_CAP;
            if ($overflow > 0) {
                $ring = array_slice($ring, $overflow);
            }

            return array_values($ring);
        }, []);
    }

    /**
     * The {@code swallowedErrors} axis as a closed map, or {@code null} while
     * warming (before the 10-min window, or when never observed). The only string
     * fields are {@code rating} and {@code caption}; every other field is a
     * number. No error text ever appears in the output.
     *
     * @return array<string,mixed>|null
     */
    public static function read(): ?array
    {
        if (RuntimeFlags::disabled()) {
            return null;
        }

        return Safe::run(static function (): ?array {
            $since = Store::get(self::KEY_SINCE, 0);
            if (!is_int($since) || $since <= 0) {
                return null; // never observed → warming
            }
            $now = Telemetry::nowMs();
            $windowMs = $now - $since;
            if ($windowMs < self::MIN_WINDOW_MS) {
                return null; // warming — omit the axis
            }
            if ($windowMs > self::TRAILING_WINDOW_MS) {
                $windowMs = self::TRAILING_WINDOW_MS;
            }
            $cutoff = $now - $windowMs;

            $ring = Store::get(self::KEY_RING, []);
            $ring = is_array($ring) ? $ring : [];
            $suppressedCount = 0;
            $handledCount = 0;
            foreach ($ring as $ev) {
                if (!is_array($ev)) {
                    continue;
                }
                $at = (int) ($ev['at'] ?? 0);
                if ($at < $cutoff) {
                    continue;
                }
                if (!empty($ev['suppressed'])) {
                    $suppressedCount++;
                } else {
                    $handledCount++;
                }
            }
            $count = $suppressedCount + $handledCount;

            $windowMin = $windowMs / 60000.0;
            $perHour = $windowMin > 0 ? ($count / $windowMin) * 60.0 : 0.0;
            $score = Score::scoreMetric($perHour, self::GOOD_PER_HOUR, self::POOR_PER_HOUR);

            return [
                'score' => $score,
                'rating' => Score::getRating($score),
                'perHour' => round($perHour * 10) / 10,
                'windowMin' => (int) round($windowMin),
                'suppressedCount' => $suppressedCount,
                'handledCount' => $handledCount,
                'caption' => self::caption($count, (int) round($windowMin)),
            ];
        }, null);
    }

    /**
     * Caption text — counts only, never content. The clean caption reads as
     * absence-of-evidence, never a guarantee.
     */
    private static function caption(int $count, int $windowMin): string
    {
        if ($count === 0) {
            return 'no swallowed errors · ' . $windowMin . ' min watched';
        }
        if ($count === 1) {
            return '1 error suppressed, app survived';
        }

        return $count . ' errors suppressed, app survived';
    }

    /** Wipe all state (wired into forget()). Idempotent. */
    public static function clear(): void
    {
        Safe::fire(static function (): void {
            Store::delete(self::KEY_SINCE);
            Store::delete(self::KEY_RING);
        });
    }

    /** Test seam: allow re-install in a fresh test process. */
    public static function resetForTests(): void
    {
        self::$installed = false;
        self::$previous = null;
        self::$inHandler = false;
    }
}
