<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * BOOSTHIS_SPAN_WORK_V1 — what KIND of work a span measured, and whether it
 * WORKED.
 *
 * This kit's copy of the shared vocabulary in lib/span-wire-vocabulary.json.
 * Every runtime carries the same eight kind names and the same two outcome
 * names, spelled identically, so "a database query" found by this kit and by
 * a Go service meet in one place on the server instead of splitting into two.
 *
 * THE FIELDS ARE OPTIONAL AND ABSENCE IS NOT A VALUE. A span with no kind came
 * from a kit that does not report one; it is not a handler, and it is not
 * other. A span with no outcome is not "ok" — nothing was said about it. The
 * server stores both as absent and every reading says "not reporting" rather
 * than inventing a clean result.
 *
 * A value not on these lists is dropped at the door rather than refusing the
 * batch. Adding one means adding it to the shared JSON first, then to every
 * kit's copy, then to the server's stored enum — never here alone.
 */
final class SpanWork
{
    /** The eight kinds of work a span may name. Closed set. */
    public const KINDS = [
        'handler',
        'db',
        'http',
        'cache',
        'queue',
        'job',
        'render',
        'other',
    ];

    /**
     * Did the measured work succeed? Closed set, and independent of the rating:
     * a fast call can fail and a slow one can succeed.
     */
    public const OUTCOMES = ['ok', 'error'];

    private function __construct()
    {
    }

    /**
     * Return the kind unchanged if it is one of the eight, otherwise null (the
     * span then reports no kind at all, which reads as "not reporting").
     */
    public static function sanitizeWorkKind(mixed $value): ?string
    {
        return is_string($value) && in_array($value, self::KINDS, true) ? $value : null;
    }

    /** Same contract for the outcome. */
    public static function sanitizeOutcome(mixed $value): ?string
    {
        return is_string($value) && in_array($value, self::OUTCOMES, true) ? $value : null;
    }

    /**
     * Decide an outcome for a call that answered with an HTTP status.
     *
     * A refusal that is a real answer — 404, 401, a validation 422 — is ok: the
     * app did what it was asked to do. Only a 5xx is the work itself failing. A
     * call that threw before any status arrived is error.
     */
    public static function outcomeForStatus(?int $status, bool $threw = false): string
    {
        if ($threw) {
            return 'error';
        }

        return $status !== null && $status >= 500 ? 'error' : 'ok';
    }
}