<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * Full-stack trace tag — PHP sibling of the RN / Node / Python / Java / Go
 * trace primitives. Byte-for-byte the same contract:
 *
 *  - header name `x-boosthis-trace`
 *  - id format exactly 32 lowercase hex chars, ^[0-9a-f]{32}$
 *  - adopt-or-mint sanitize-on-receive: an incoming id is adopted only if it
 *    matches the format, otherwise a fresh one is minted (so a caller can never
 *    smuggle PII or a header-injection payload through the header)
 *  - companion elapsed header `x-boosthis-trace-elapsed`, a bounded
 *    non-negative integer chained from each hop's own monotonic delta
 *
 * Under BOOSTHIS_DISABLED the request wrappers pass straight through and never
 * mint or echo a trace id.
 */
final class Trace
{
    /** Canonical lowercase header name carried on every instrumented request. */
    public const TRACE_HEADER = 'x-boosthis-trace';
    /** A valid trace id is exactly 32 lowercase hex characters (128 bits). */
    public const TRACE_ID_RE = '/^[0-9a-f]{32}$/';
    /** Companion header carrying the relative elapsed-ms offset for the next hop. */
    public const ELAPSED_HEADER = 'x-boosthis-trace-elapsed';
    /** A propagated elapsed value is 1-6 digits (0..999999); clamped on receive. */
    public const TRACE_ELAPSED_RE = '/^\d{1,6}$/';
    /** Upper clamp for a propagated elapsed value (mirrors MAX_SPAN_DURATION_MS). */
    public const MAX_TRACE_ELAPSED_MS = 600000;

    private static ?string $currentTraceId = null;
    private static int $currentElapsedBase = 0;
    private static ?float $currentRequestStart = null;
    private static ?string $currentSpanId = null;
    private static ?string $currentParentSpanId = null;

    private function __construct()
    {
    }

    /** True if the value matches the locked trace-id format exactly. */
    public static function isValidTraceId(?string $value): bool
    {
        return $value !== null && preg_match(self::TRACE_ID_RE, $value) === 1;
    }

    /** Mint a fresh 32-hex-char trace id using the CSPRNG (16 random bytes). */
    public static function newTraceId(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            // random_bytes only fails when the platform has no CSPRNG at all.
            // A trace id is a correlation tag, not a secret, so degrade rather
            // than break the host request.
            return bin2hex(pack('NNNN', mt_rand(), mt_rand(), mt_rand(), mt_rand()));
        }
    }

    /** Adopt-or-mint: return $value if it is a valid trace id, else mint. */
    public static function sanitizeTraceId(?string $value): string
    {
        return self::isValidTraceId($value) ? (string) $value : self::newTraceId();
    }

    /**
     * Adopt the incoming trace id from a header map (or mint a fresh one).
     *
     * @param array<string,string>|null $headers
     */
    public static function readTraceId(?array $headers): string
    {
        $v = null;
        foreach ($headers ?? [] as $k => $val) {
            if (strtolower((string) $k) === self::TRACE_HEADER) {
                $v = (string) $val;
                break;
            }
        }

        return self::sanitizeTraceId($v);
    }

    /** Sanitize-on-receive for the elapsed chain: bounded int, else 0. */
    public static function sanitizeTraceElapsed(?string $value): int
    {
        if ($value !== null && preg_match(self::TRACE_ELAPSED_RE, $value) === 1) {
            return min(self::MAX_TRACE_ELAPSED_MS, (int) $value);
        }

        return 0;
    }

    /**
     * Adopt the trace context off a PHP superglobal-style server array. PHP
     * hands headers over as HTTP_X_BOOSTHIS_TRACE, so the mapping is done here
     * once rather than in every framework adapter.
     *
     * @param array<string,mixed> $server
     */
    public static function beginRequest(array $server): string
    {
        $traceId = self::sanitizeTraceId(
            isset($server['HTTP_X_BOOSTHIS_TRACE']) ? (string) $server['HTTP_X_BOOSTHIS_TRACE'] : null
        );
        $elapsed = self::sanitizeTraceElapsed(
            isset($server['HTTP_X_BOOSTHIS_TRACE_ELAPSED'])
                ? (string) $server['HTTP_X_BOOSTHIS_TRACE_ELAPSED']
                : null
        );
        $parentSpanId = SpanScope::adoptParentSpanId(
            $server['HTTP_X_BOOSTHIS_TRACE_PARENT'] ?? null
        );
        $requestSpan = SpanScope::beginSpan($parentSpanId);
        self::$currentTraceId = $traceId;
        self::$currentElapsedBase = $elapsed;
        self::$currentRequestStart = self::nowMs();
        self::$currentSpanId = $requestSpan->spanId;
        self::$currentParentSpanId = $requestSpan->parentSpanId;

        return $traceId;
    }

    public static function currentTraceId(): ?string
    {
        return self::$currentTraceId;
    }

    public static function elapsedBase(): int
    {
        return self::$currentElapsedBase;
    }

    public static function requestStartMs(): ?float
    {
        return self::$currentRequestStart;
    }

    /** This request's own span identity, or null outside a request. */
    public static function currentSpanId(): ?string
    {
        return SpanScope::sanitizeSpanId(self::$currentSpanId);
    }

    /** The caller adopted by this request, or null when it is top-level. */
    public static function currentParentSpanId(): ?string
    {
        return SpanScope::sanitizeSpanId(self::$currentParentSpanId);
    }

    private static function elapsedSinceRequestMs(): float
    {
        if (self::$currentRequestStart === null) {
            return 0.0;
        }
        $d = self::nowMs() - self::$currentRequestStart;

        return $d > 0 ? $d : 0.0;
    }

    /**
     * Headers to attach to an outbound call so the next hop joins this trace.
     * The elapsed value is this hop's own base plus the time spent here, so the
     * chain stays monotonic across services without a shared clock.
     *
     * @return array<string,string>
     */
    public static function outboundHeaders(): array
    {
        if (RuntimeFlags::disabled() || self::$currentTraceId === null) {
            return [];
        }
        $elapsed = (int) round(self::$currentElapsedBase + self::elapsedSinceRequestMs());
        if ($elapsed < 0) {
            $elapsed = 0;
        }
        if ($elapsed > self::MAX_TRACE_ELAPSED_MS) {
            $elapsed = self::MAX_TRACE_ELAPSED_MS;
        }

        $headers = [
            self::TRACE_HEADER => self::$currentTraceId,
            self::ELAPSED_HEADER => (string) $elapsed,
        ];
        $spanId = SpanScope::sanitizeSpanId(SpanScope::currentSpanId() ?? self::$currentSpanId);
        if ($spanId !== null) {
            $headers[SpanScope::PARENT_HEADER] = $spanId;
        }

        return $headers;
    }

    /**
     * Offset from the trace root at which a just-finished measurement STARTED.
     * The measurement ended NOW and ran for $durationMs, so it began at
     * base + max(0, time-in-request − $durationMs). Clamped to
     * [0, MAX_TRACE_ELAPSED_MS], never negative; 0 outside any request context.
     * Sibling of Java Trace::spanStartOffset / Python span_start_offset — the
     * value a span reports as startOffsetMs.
     */
    public static function spanStartOffset(float $durationMs): int
    {
        $base = self::$currentElapsedBase;
        if ($base < 0) {
            $base = 0;
        }
        $d = $durationMs > 0 ? $durationMs : 0.0;
        $inRequest = self::elapsedSinceRequestMs() - $d;
        if ($inRequest < 0) {
            $inRequest = 0.0;
        }
        $started = (int) round($base + $inRequest);
        if ($started < 0) {
            $started = 0;
        }
        if ($started > self::MAX_TRACE_ELAPSED_MS) {
            $started = self::MAX_TRACE_ELAPSED_MS;
        }

        return $started;
    }

    public static function nowMs(): float
    {
        return microtime(true) * 1000.0;
    }

    /** Test seam. */
    public static function reset(): void
    {
        self::$currentTraceId = null;
        self::$currentElapsedBase = 0;
        self::$currentRequestStart = null;
        self::$currentSpanId = null;
        self::$currentParentSpanId = null;
        SpanScope::resetForTests();
    }
}
