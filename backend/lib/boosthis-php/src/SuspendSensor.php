<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * Host-suspend sensor — PHP port of the shared cross-runtime sensor
 * (Go's suspend_sensor.go, Node's suspendSensor.ts).
 *
 * Scale-to-zero / autoscale hosts SUSPEND the container between requests:
 * timers fire tens of seconds late and the first requests after a wake-up pay
 * the resume cost. A suspend-poisonable meter (loop lag / worst freeze /
 * latency floor / CPU throttling) would otherwise record those gaps as
 * terrifying reds even though no user-facing work was ever stalled. This is the
 * shared detector such meters consult so they can DISCOUNT — never hide —
 * readings attributable to a host suspend.
 *
 * PHP-SPECIFIC LIFECYCLE. Every other runtime keeps the "last activity"
 * timestamp and the wake-window ring in a long-lived process. PHP has none: each
 * request is a fresh process, so the only observation boundary that exists is
 * "a request just started", and the only place the gap between two requests can
 * be seen is in cross-request state. So this sensor keys purely on the wall
 * clock recorded at the previous request boundary, held in Store. Detection
 * rule is identical to the siblings:
 *
 *   - A request arriving after a gap >= SUSPEND_GAP_MS with no activity in
 *     between is a WAKE — the idle gap before it is where a suspend can have
 *     happened.
 *   - For SUSPEND_WAKE_WINDOW_MS after a wake, samples reflect resume cost, not
 *     the app's own steady-state code, so a suspend-poisonable meter discounts
 *     any reading whose timestamp falls inside that window.
 *
 * PRIVACY. Timestamps and counts only; nothing here ever sees a route, URL, or
 * customer string. The ONLY fields that survive the kit's PII pass onto the wire
 * are `suspendDiscounts` and `suspendWorstMs` — the server's closed shape
 * allowlists exactly those two names and silently strips anything else. This
 * class does not build the wire object itself; it exposes the two numbers so the
 * poisonable meters attach them to their own axis exactly as the siblings do.
 *
 * GUEST-SAFETY. Wired onto the EXISTING request boundary (the same
 * enable/record path the meters already run), never a new timer or poller. All
 * work runs through Safe/Store, so a bug degrades to "no discount", never an
 * exception in the host request.
 */
final class SuspendSensor
{
    private function __construct()
    {
    }

    // ── Shared constants (byte-parity with every runtime) ─────────────────────

    /**
     * An idle gap at/above this (ms) is long enough for the host to have
     * suspended the container; the wake after it opens a discount window. Same
     * constant across every runtime (Go: suspendGapMs).
     */
    public const SUSPEND_GAP_MS = 10_000;

    /**
     * How long (ms) after a wake request samples are attributed to resume cost
     * rather than the app's own code. Same constant across every runtime (Go:
     * suspendWakeWindowMs).
     */
    public const SUSPEND_WAKE_WINDOW_MS = 60_000;

    /**
     * Bounds the retained wake-window ring so a long-lived deployment on a flaky
     * host can never grow it unbounded. Same constant across every runtime (Go:
     * suspendMaxWakeWindows).
     */
    public const SUSPEND_MAX_WAKE_WINDOWS = 256;

    // ── Cross-request state keys (PHP has no process to hold these) ───────────

    /** Wall clock (epoch ms) of the last observed request boundary. */
    private const KEY_LAST_ACTIVITY = 'suspend.lastActivityMs';
    /** How many suspend-suspicious wakes have been seen this session. */
    private const KEY_WAKE_COUNT = 'suspend.wakeCount';
    /** Bounded ring of [startMs, endMs] post-wake windows. */
    private const KEY_WAKE_WINDOWS = 'suspend.wakeWindows';
    /** Worst discounted reading (ms) seen inside any wake window so far. */
    private const KEY_WORST_MS = 'suspend.worstMs';

    /**
     * Observe one request boundary. Called at the start of every instrumented
     * request (the same boundary the sample recorder already owns — no second
     * timer). Detects a wake after a suspicious idle gap and, when found, opens
     * a discount window and returns the gap in ms; returns 0 when this arrival
     * was not a wake. No-op (returns 0) under the kill-switch. Never throws.
     */
    public static function noteRequestBoundary(): int
    {
        if (RuntimeFlags::disabled()) {
            return 0;
        }

        return (int) Safe::run(static function (): int {
            $now = Telemetry::nowMs();
            $last = Store::get(self::KEY_LAST_ACTIVITY, 0);
            $last = is_int($last) ? $last : 0;

            $gap = 0;
            if ($last > 0) {
                $delta = $now - $last;
                if ($delta >= self::SUSPEND_GAP_MS) {
                    $gap = $delta;
                    self::openWakeWindow($now);
                }
            }
            Store::set(self::KEY_LAST_ACTIVITY, $now);

            return $gap;
        }, 0);
    }

    /**
     * Record the newly-opened post-wake window and bump the wake count, under
     * the same exclusive lock so concurrent FPM workers do not lose the update.
     */
    private static function openWakeWindow(int $now): void
    {
        Store::update(self::KEY_WAKE_COUNT, static function ($cur): int {
            $cur = is_int($cur) ? $cur : 0;

            return $cur + 1;
        }, 0);

        Store::update(self::KEY_WAKE_WINDOWS, static function ($cur) use ($now): array {
            $windows = is_array($cur) ? $cur : [];
            $windows[] = [$now, $now + self::SUSPEND_WAKE_WINDOW_MS];
            // Bound the ring: drop the oldest windows first.
            $overflow = count($windows) - self::SUSPEND_MAX_WAKE_WINDOWS;
            if ($overflow > 0) {
                $windows = array_slice($windows, $overflow);
            }

            return array_values($windows);
        }, []);
    }

    /**
     * True when $tsMs falls inside any recorded post-wake window (resume-cost
     * territory). A suspend-poisonable meter calls this for each sample and
     * discounts the ones that return true. Pure read over the bounded ring.
     */
    public static function inWakeWindow(int $tsMs): bool
    {
        if (RuntimeFlags::disabled()) {
            return false;
        }

        return (bool) Safe::run(static function () use ($tsMs): bool {
            $windows = Store::get(self::KEY_WAKE_WINDOWS, []);
            if (!is_array($windows)) {
                return false;
            }
            foreach ($windows as $w) {
                if (!is_array($w) || count($w) < 2) {
                    continue;
                }
                $start = (int) $w[0];
                $end = (int) $w[1];
                if ($tsMs >= $start && $tsMs <= $end) {
                    return true;
                }
            }

            return false;
        }, false);
    }

    /**
     * Record that a reading of $ms was discounted because it fell in a wake
     * window, keeping the worst such reading. A poisonable meter calls this when
     * it drops a sample so `suspendWorstMs` reflects the largest artifact the
     * discount hid. No-op under the kill-switch. Never throws.
     */
    public static function noteDiscountedReading(int $ms): void
    {
        if (RuntimeFlags::disabled() || $ms <= 0) {
            return;
        }
        Safe::fire(static function () use ($ms): void {
            Store::update(self::KEY_WORST_MS, static function ($cur) use ($ms): int {
                $cur = is_int($cur) ? $cur : 0;

                return $ms > $cur ? $ms : $cur;
            }, 0);
        });
    }

    /**
     * How many suspend-suspicious wakes have been seen this session. Ships as
     * the wire field `suspendDiscounts` (allowlisted) — attached by the
     * poisonable meter only when > 0.
     */
    public static function discountCount(): int
    {
        if (RuntimeFlags::disabled()) {
            return 0;
        }

        return (int) Safe::run(static function (): int {
            $v = Store::get(self::KEY_WAKE_COUNT, 0);

            return is_int($v) ? $v : 0;
        }, 0);
    }

    /**
     * Worst discounted reading (ms) seen inside a wake window this session, or
     * 0 when none. Ships as the wire field `suspendWorstMs` (allowlisted) —
     * attached by the poisonable meter only when > 0.
     */
    public static function worstMs(): int
    {
        if (RuntimeFlags::disabled()) {
            return 0;
        }

        return (int) Safe::run(static function (): int {
            $v = Store::get(self::KEY_WORST_MS, 0);

            return is_int($v) ? $v : 0;
        }, 0);
    }

    /** Wipe all sensor state (wired into forget()). Idempotent. */
    public static function clear(): void
    {
        Safe::fire(static function (): void {
            Store::delete(self::KEY_LAST_ACTIVITY);
            Store::delete(self::KEY_WAKE_COUNT);
            Store::delete(self::KEY_WAKE_WINDOWS);
            Store::delete(self::KEY_WORST_MS);
        });
    }
}
