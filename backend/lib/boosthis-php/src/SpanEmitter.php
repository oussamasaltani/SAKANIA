<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * Full-stack trace spans. One span is one measured leg of a request, tagged
 * with the trace id so the dashboard can stitch a browser click to the PHP
 * handler that served it.
 *
 * THE WIRE RULE: a span is rebuilt as a CLOSED map — the six required fields,
 * plus sanitized spanId, parentSpanId, kind and outcome only when present —
 * never spread from an internal object. Every runtime does it this way so a new
 * internal field can never leak onto the wire by accident.
 *
 * PHP has no process to buffer in, so spans accumulate in the cross-request
 * store and flush in batches after the response has been handed back to the
 * client.
 */
final class SpanEmitter
{
    public const LAYER = 'php';
    public const MAX_SPAN_DURATION_MS = 600000;
    public const MAX_BUFFERED_SPANS = 200;
    private const KEY = 'spans';

    private function __construct()
    {
    }

    /**
     * Record one span. Silently drops anything that fails the route-label guard
     * — a label the guard rejects is a label we must not transmit.
     */
    public static function record(
        string $traceId,
        string $routeLabel,
        float $durationMs,
        float $startOffsetMs = 0.0,
        mixed $spanId = null,
        mixed $parentSpanId = null,
        mixed $kind = null,
        mixed $outcome = null
    ): void {
        if (RuntimeFlags::disabled()) {
            return;
        }
        Safe::fire(static function () use (
            $traceId,
            $routeLabel,
            $durationMs,
            $startOffsetMs,
            $spanId,
            $parentSpanId,
            $kind,
            $outcome
        ): void {
            if (!Trace::isValidTraceId($traceId)) {
                return;
            }
            if (Pii::checkRouteLabel($routeLabel) !== null) {
                return;
            }
            $duration = (int) round(max(0.0, min($durationMs, self::MAX_SPAN_DURATION_MS)));
            $offset = (int) round(max(0.0, min($startOffsetMs, self::MAX_SPAN_DURATION_MS)));
            $span = self::buildWireSpan(
                $traceId,
                $routeLabel,
                $duration,
                $offset,
                Score::getDurationRating((float) $duration),
                $spanId,
                $parentSpanId,
                $kind,
                $outcome
            );
            Store::update(self::KEY, static function ($cur) use ($span) {
                $list = is_array($cur) ? $cur : [];
                $list[] = $span;
                if (count($list) > self::MAX_BUFFERED_SPANS) {
                    $list = array_slice($list, -self::MAX_BUFFERED_SPANS);
                }

                return $list;
            }, []);
        });
    }

    /**
     * The closed wire map. Optional identities are omitted when absent.
     *
     * @return array<string,mixed>
     */
    public static function buildWireSpan(
        string $traceId,
        string $routeLabel,
        int $durationMs,
        int $startOffsetMs,
        string $rating,
        mixed $spanId = null,
        mixed $parentSpanId = null,
        mixed $kind = null,
        mixed $outcome = null
    ): array {
        $wire = [
            'traceId' => $traceId,
            'layer' => self::LAYER,
            'routeLabel' => $routeLabel,
            'durationMs' => $durationMs,
            'startOffsetMs' => $startOffsetMs,
            'rating' => $rating,
        ];
        $own = SpanScope::sanitizeSpanId($spanId);
        if ($own !== null) {
            $wire['spanId'] = $own;
        }
        $parent = SpanScope::sanitizeSpanId($parentSpanId);
        if ($parent !== null) {
            $wire['parentSpanId'] = $parent;
        }
        $workKind = SpanWork::sanitizeWorkKind($kind);
        if ($workKind !== null) {
            $wire['kind'] = $workKind;
        }
        $workOutcome = SpanWork::sanitizeOutcome($outcome);
        if ($workOutcome !== null) {
            $wire['outcome'] = $workOutcome;
        }

        return $wire;
    }

    /** @return list<array<string,mixed>> */
    public static function buffered(): array
    {
        $cur = Store::get(self::KEY, []);

        return is_array($cur) ? array_values($cur) : [];
    }

    /**
     * Hand the buffer to the caller and clear it in one locked step, so two
     * workers flushing at once cannot upload the same span twice.
     *
     * @return list<array<string,mixed>>
     */
    public static function drain(): array
    {
        $taken = [];
        Store::update(self::KEY, static function ($cur) use (&$taken) {
            $taken = is_array($cur) ? array_values($cur) : [];

            return [];
        }, []);

        return $taken;
    }

    public static function clear(): void
    {
        Store::delete(self::KEY);
    }
}
