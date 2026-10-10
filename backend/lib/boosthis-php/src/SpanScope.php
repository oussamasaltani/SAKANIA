<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * Active-span scope — the part of full-stack causality that answers which call
 * caused which. A trace id groups hops; spanId identifies this call and
 * parentSpanId identifies the call that caused it.
 *
 * Parent ids are ADOPT-OR-DROP, never adopt-or-mint. Inventing a replacement
 * parent would fabricate a cause indistinguishable from a real call. The
 * ambient scope is deliberately synchronous: it closes when runInSpan()
 * returns, including on a throw. Holding it across suspended asynchronous work
 * could falsely report concurrent calls as parent and child; a wrong parent is
 * worse than an honestly flat trace.
 *
 * Privacy contract: an id is exactly 16 lowercase hexadecimal characters.
 * Pure hex cannot match the PII guard's value patterns, and spanId,
 * parentSpanId, and x-boosthis-trace-parent tokenize cleanly. The value is
 * random, ephemeral, and never derived from user data.
 *
 * A span id must NEVER appear in a route or screen label.
 */
final class SpanScope
{
    /** Companion header carrying the caller's span id to the next hop. */
    public const PARENT_HEADER = 'x-boosthis-trace-parent';
    /** A valid span id is exactly 16 lowercase hex characters (64 bits). */
    public const SPAN_ID_RE = '/^[0-9a-f]{16}$/';
    /** Ceiling on synchronous ambient scope depth. */
    public const MAX_ACTIVE_SPANS = 32;

    /** @var list<SpanHandle> */
    private static array $active = [];

    private function __construct()
    {
    }

    /** True only for the locked 16-lowercase-hex shape. */
    public static function isValidSpanId(mixed $value): bool
    {
        return is_string($value) && preg_match(self::SPAN_ID_RE, $value) === 1;
    }

    /**
     * Mint eight CSPRNG bytes as lowercase hex. As with the trace id, the
     * fallback is acceptable because this is a correlation tag, not a secret.
     */
    public static function newSpanId(): string
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            return bin2hex(pack('NN', mt_rand(), mt_rand()));
        }
    }

    /** Adopt a valid identity unchanged; drop every other value. Never mints. */
    public static function sanitizeSpanId(mixed $value): ?string
    {
        return self::isValidSpanId($value) ? $value : null;
    }

    /**
     * Normalize an inbound header (an array uses its first element), then
     * adopt-or-drop. Never throws.
     */
    public static function adoptParentSpanId(mixed $headerValue): ?string
    {
        try {
            $raw = is_array($headerValue)
                ? ($headerValue === [] ? null : reset($headerValue))
                : $headerValue;

            return self::sanitizeSpanId($raw);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Innermost open synchronous span, or null. */
    public static function currentSpanId(): ?string
    {
        if (self::$active === []) {
            return null;
        }

        return self::$active[array_key_last(self::$active)]->spanId;
    }

    /**
     * The action this PHP request belongs to, or null when no request scope is
     * held. PHP keeps that trace and request-span pair in request-scoped static
     * state for parentage; reading that same pair names the action genuinely
     * current here rather than guessing from another request in flight.
     *
     * @return null|array{traceId:string,spanId:string}
     */
    public static function currentAction(): ?array
    {
        try {
            $traceId = Trace::currentTraceId();
            $spanId = Trace::currentSpanId();
            if (!Trace::isValidTraceId($traceId) || !self::isValidSpanId($spanId)) {
                return null;
            }

            return ['traceId' => $traceId, 'spanId' => $spanId];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Mint a span. An explicit argument (including null) names its caller;
     * omitting the argument uses the innermost open synchronous span.
     */
    public static function beginSpan(?string $parentSpanId = null): SpanHandle
    {
        $parent = func_num_args() > 0
            ? self::sanitizeSpanId($parentSpanId)
            : self::currentSpanId();

        return new SpanHandle(self::newSpanId(), $parent);
    }

    /**
     * Run synchronously with $span ambient. The frame is removed by object
     * identity on return or throw. At the depth ceiling the callback still runs
     * and the scope simply stops deepening.
     */
    public static function runInSpan(SpanHandle $span, callable $fn): mixed
    {
        $added = count(self::$active) < self::MAX_ACTIVE_SPANS;
        if ($added) {
            self::$active[] = $span;
        }
        try {
            return $fn();
        } finally {
            if ($added) {
                for ($i = count(self::$active) - 1; $i >= 0; $i--) {
                    if (self::$active[$i] === $span) {
                        array_splice(self::$active, $i, 1);
                        break;
                    }
                }
            }
        }
    }

    /** Test seam: number of active synchronous frames. */
    public static function activeCountForTests(): int
    {
        return count(self::$active);
    }

    /** Test seam: clear all active synchronous frames. */
    public static function resetForTests(): void
    {
        self::$active = [];
    }
}

/** One call's own identity and its caller's identity. */
final class SpanHandle
{
    public function __construct(
        public readonly string $spanId,
        public readonly ?string $parentSpanId
    ) {
    }
}