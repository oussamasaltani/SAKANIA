<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * The {@code leakWatch} additive meter axis (PHP) — the parity sibling of the
 * shared cross-runtime "Leak Watch" axis. It detects the CUSTOMER's app leaking
 * stack traces, secrets/keys, or personal details. Runtime evidence only; NEVER
 * feeds the Speed score; additive and display-only, exactly like
 * {@code swallowedErrors}.
 *
 * PHP OBSERVATION SCOPE. The shared contract asks each runtime to scan whatever
 * honest stream it has. The Java kit scans error-response bodies through its
 * response wrapper and ships NO logging hook. The PHP kit has no drop-in
 * response wrapper (a guest kit cannot commandeer the host's output buffer
 * without risking its bytes), but it DOES already sit on the process-wide error
 * handler that {@link SwallowedErrors} installs. That single chained hook is the
 * observable stream here: the message of every warning/notice/deprecation/error
 * that passes through it, plus anything the host raises with
 * {@code trigger_error}. This is a PIGGYBACK on the existing hook — never a
 * second handler — so host behaviour is untouched (see SwallowedErrors for the
 * chain-and-delegate discipline).
 *
 * Because the source is the error-message stream and NOT a request/response
 * path, there is no route to classify: this axis honestly omits
 * {@code routeClassCount} (which the contract reserves for the server kits that
 * scan responses). Every field it DOES emit uses the same key, value shape and
 * gates as Java's {@code leakWatch}.
 *
 * ABSOLUTE PRIVACY RULE. The matched text, the matched value, and the message
 * are NEVER stored beyond the local classification scope, never logged, and
 * never placed in any field, caption, or error. Only per-category counters,
 * event timestamps, and a per-category tag ever leave the scan. The message
 * buffer is discarded the instant classification finishes.
 *
 * GATES & SCORING (mirror Java {@code LeakWatch}). A 5-min minimum observation
 * window before the axis reports at all (omitted → warming); a 60-min trailing
 * window; a bounded ring capped at RING_CAP of {ts, category} only.
 * {@code score = 100} when the window is clean; otherwise
 * {@code max(0, 70 - round(perHour * 10))} — any observed leak is at best
 * needs-work. The clean caption reads as absence-of-evidence, never "protected".
 *
 * FIELD NAMES. {@code secretCount / stackCount / piiCount} normalize to contain
 * denied fragments ("secret", "pii") and are ALLOWLISTED in {@link Pii}; the
 * remaining fields ({@code score, rating, count, perHour, windowMin, caption})
 * are clean. Verified against {@code Pii::findDeniedFragment()}.
 */
final class LeakWatch
{
    private function __construct()
    {
    }

    // ── Gates (shared cross-runtime contract, byte-parity with Java) ─────────

    /** Minimum observation window before the axis reports (5 min). */
    public const MIN_WINDOW_MS = 5 * 60 * 1000;
    /** Trailing window the counts are computed over (60 min). */
    public const TRAILING_WINDOW_MS = 60 * 60 * 1000;
    /** Bounded ring of recent events so persisted state never grows unbounded. */
    public const RING_CAP = 200;
    /** Message scan cap (chars) — only the first 4 KB of a message is scanned. */
    public const LOG_SCAN_CHARS = 4096;

    // ── Categories (priority secret > pii > stack; each event is ONE) ────────

    private const CAT_SECRET = 'secret';
    private const CAT_PII = 'pii';
    private const CAT_STACK = 'stack';

    // ── Conservative patterns (contract-locked; byte-parity with Java) ───────

    /** JS/Node "at fn (file:line:col)". */
    private const STACK_JS_FRAME = '/\bat .{1,200}\(.{1,200}:\d+:\d+\)/';
    private const STACK_JS_ERROR = '/Error:\s*\n\s*at /';
    /** Java frame "at pkg.Class.method(File.java:123)". */
    private const STACK_JAVA_FRAME = '/\bat [\w.$]+\([\w$]+\.java:\d+\)/';
    /** Go "goroutine N [" or "*.go:123 +0x". */
    private const STACK_GO_GOROUTINE = '/goroutine \d+ \[/';
    private const STACK_GO_FRAME = '/\.go:\d+ \+0x/';
    /** Python traceback header (plain substring). */
    private const STACK_PY_TRACEBACK = 'Traceback (most recent call last)';
    /** PHP frame "#0 /path/file.php(123): Class->method()". */
    private const STACK_PHP_FRAME = '/#\d+ .{1,200}\.php\(\d+\):/';

    /** JWT: three dot-separated base64url segments beginning eyJ. */
    private const SECRET_JWT =
        '/eyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+/';
    private const SECRET_BEARER = '/bearer\s+\S{8,}/i';
    private const SECRET_AKIA = '/AKIA[0-9A-Z]{16}/';
    private const SECRET_PRIVATE_KEY = '/-----BEGIN [A-Z ]*PRIVATE KEY/';
    private const SECRET_ASSIGN =
        '/(api[_\-]?key|secret|token)["\']?\s*[:=]\s*["\']?[A-Za-z0-9_\-]{16,}/i';

    // ── Cross-request state keys (PHP has no process to hold these) ──────────

    /** When observation began (epoch ms) — the 5-min gate measures from here. */
    private const KEY_SINCE = 'leak.since';
    /** Bounded ring of events: list of ['at'=>int,'cat'=>string]. */
    private const KEY_RING = 'leak.ring';

    /** Reentrancy guard: never scan a message raised inside our own scan. */
    private static bool $inScan = false;

    /**
     * Observe one message from the chained error handler (the ONLY hook; called
     * by {@link SwallowedErrors::handle}). Classifies into exactly one leak
     * category and, on a hit, records a content-free event. Bounded work; fully
     * self-guarded; never throws; no-op under the kill-switch. The message is
     * used only inside this call and never stored.
     */
    public static function observeMessage(?string $message): void
    {
        if (RuntimeFlags::disabled() || self::$inScan) {
            return;
        }
        self::$inScan = true;
        Safe::fire(static function () use ($message): void {
            if ($message === null || $message === '') {
                return;
            }
            self::ensureObserving();
            $text = strlen($message) > self::LOG_SCAN_CHARS
                ? substr($message, 0, self::LOG_SCAN_CHARS)
                : $message;
            $category = self::classify($text);
            if ($category === null) {
                return;
            }
            self::record($category);
        });
        self::$inScan = false;
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
     * Classify a bounded text into exactly ONE category, or {@code null} when it
     * matches none. Priority is {@code secret > pii > stack} so per-category
     * counts always sum to the total. The matched value is NEVER returned or
     * stored — only the category tag.
     */
    public static function classify(string $text): ?string
    {
        if ($text === '') {
            return null;
        }
        if (self::matchesSecret($text)) {
            return self::CAT_SECRET;
        }
        if (self::matchesPii($text)) {
            return self::CAT_PII;
        }
        if (self::matchesStack($text)) {
            return self::CAT_STACK;
        }

        return null;
    }

    private static function matchesSecret(string $t): bool
    {
        return preg_match(self::SECRET_JWT, $t) === 1
            || preg_match(self::SECRET_BEARER, $t) === 1
            || preg_match(self::SECRET_AKIA, $t) === 1
            || preg_match(self::SECRET_PRIVATE_KEY, $t) === 1
            || preg_match(self::SECRET_ASSIGN, $t) === 1;
    }

    private static function matchesPii(string $t): bool
    {
        // Reuse the kit's EXISTING PII detectors (no duplicate patterns).
        // containsEmail returns a boolean only; findDeniedValue returns a tag
        // (never the matched text) which we collapse to a boolean here.
        return Pii::containsEmail($t) || Pii::findDeniedValue($t) !== null;
    }

    private static function matchesStack(string $t): bool
    {
        return preg_match(self::STACK_JS_FRAME, $t) === 1
            || preg_match(self::STACK_JS_ERROR, $t) === 1
            || preg_match(self::STACK_JAVA_FRAME, $t) === 1
            || preg_match(self::STACK_GO_GOROUTINE, $t) === 1
            || preg_match(self::STACK_GO_FRAME, $t) === 1
            || preg_match(self::STACK_PHP_FRAME, $t) === 1
            || str_contains($t, self::STACK_PY_TRACEBACK);
    }

    /**
     * File one observation into the bounded, cross-request ring. Category is a
     * closed-set tag; NO content is ever stored.
     */
    private static function record(string $category): void
    {
        Store::update(self::KEY_RING, static function ($cur) use ($category): array {
            $ring = is_array($cur) ? $cur : [];
            $ring[] = ['at' => Telemetry::nowMs(), 'cat' => $category];
            $overflow = count($ring) - self::RING_CAP;
            if ($overflow > 0) {
                $ring = array_slice($ring, $overflow);
            }

            return array_values($ring);
        }, []);
    }

    /**
     * The {@code leakWatch} axis as a closed map, or {@code null} while warming
     * (before the 5-min window, or when never observed). The only string fields
     * are {@code rating} and {@code caption}; every other field is a number. No
     * scanned content ever appears in the output.
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
            $count = 0;
            $stackCount = 0;
            $secretCount = 0;
            $piiCount = 0;
            foreach ($ring as $ev) {
                if (!is_array($ev)) {
                    continue;
                }
                $at = (int) ($ev['at'] ?? 0);
                if ($at < $cutoff) {
                    continue;
                }
                $cat = (string) ($ev['cat'] ?? '');
                $count++;
                if ($cat === self::CAT_SECRET) {
                    $secretCount++;
                } elseif ($cat === self::CAT_PII) {
                    $piiCount++;
                } elseif ($cat === self::CAT_STACK) {
                    $stackCount++;
                }
            }

            $windowMin = $windowMs / 60000.0;
            $perHour = $windowMin > 0 ? ($count / $windowMin) * 60.0 : 0.0;

            $score = $count === 0
                ? 100
                : max(0, 70 - (int) round($perHour * 10));

            return [
                'score' => $score,
                'rating' => Score::getRating($score),
                'count' => $count,
                'perHour' => round($perHour * 100) / 100,
                'windowMin' => (int) round($windowMin),
                'stackCount' => $stackCount,
                'secretCount' => $secretCount,
                'piiCount' => $piiCount,
                'caption' => self::caption($count, $stackCount, $secretCount, $piiCount),
            ];
        }, null);
    }

    /**
     * Caption text — counts and CATEGORY WORDS only, never content. The clean
     * caption reads as absence-of-evidence, never a guarantee of protection.
     */
    public static function caption(int $count, int $stackCount, int $secretCount, int $piiCount): string
    {
        if ($count === 0) {
            return 'no leaks observed in this window';
        }
        $parts = [];
        if ($secretCount > 0) {
            $parts[] = $secretCount . ' secret-like';
        }
        if ($piiCount > 0) {
            $parts[] = $piiCount . ' personal-detail';
        }
        if ($stackCount > 0) {
            $parts[] = $stackCount . ' stack trace';
        }
        $noun = $count === 1 ? 'possible leak' : 'possible leaks';

        return $count . ' ' . $noun . ' observed (' . implode(', ', $parts) . ')';
    }

    /** Wipe all state (wired into forget()). Idempotent. */
    public static function clear(): void
    {
        Safe::fire(static function (): void {
            Store::delete(self::KEY_SINCE);
            Store::delete(self::KEY_RING);
        });
    }
}
