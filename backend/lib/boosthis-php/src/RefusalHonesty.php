<?php

declare(strict_types=1);

namespace Boosthis;

/** Presence-only refusal telemetry. Header values never enter this collector. */
final class RefusalHonesty
{
    public const AXIS = 'refusalHonesty';
    public const REFUSAL_MIN_WINDOW_MS = 600000;
    public const WARMING_CAPTION = 'warming up · 10 min minimum window';
    private const KEY = 'refusal_honesty';
    private static $nowFn = null;

    private function __construct()
    {
    }

    private static function now(): int
    {
        return self::$nowFn !== null ? (int) (self::$nowFn)() : Telemetry::nowMs();
    }

    public static function recordResponse(
        int $status,
        bool $authorizationPresent,
        bool $challengePresent,
        bool $measurable = true
    ): void {
        if (RuntimeFlags::disabled()) {
            return;
        }
        Safe::fire(static function () use ($status, $authorizationPresent, $challengePresent, $measurable): void {
            Store::update(self::KEY, static function ($cur) use (
                $status,
                $authorizationPresent,
                $challengePresent,
                $measurable
            ): array {
                $s = is_array($cur) ? $cur : [];
                $s += ['startedAt' => self::now(), 'refusals' => 0, 'challenged' => 0,
                    'credentialed' => 0, 'misleading' => 0, 'measurable' => 1];
                if (!$measurable) {
                    $s['measurable'] = 0;
                    return $s;
                }
                if ($status !== 401 && $status !== 403) {
                    return $s;
                }
                $s['refusals']++;
                if ($challengePresent) {
                    $s['challenged']++;
                }
                if ($authorizationPresent) {
                    $s['credentialed']++;
                }
                if ($status === 401 && $challengePresent && $authorizationPresent) {
                    $s['misleading']++;
                }
                return $s;
            }, []);
        });
    }

    public static function read(): ?array
    {
        return Safe::run(static function (): ?array {
            $s = Store::get(self::KEY, null);
            if (!is_array($s)) {
                return null;
            }
            $elapsed = max(0, self::now() - (int) ($s['startedAt'] ?? self::now()));
            if ($elapsed < self::REFUSAL_MIN_WINDOW_MS) {
                return null;
            }
            $measurable = (int) ($s['measurable'] ?? 1);
            $refusals = (int) ($s['refusals'] ?? 0);
            $misleading = (int) ($s['misleading'] ?? 0);
            $sharePct = $refusals === 0 ? 0 : (int) round($misleading / $refusals * 100);
            $windowMin = intdiv($elapsed, 60000);
            $caption = $measurable === 0
                ? 'not measurable — this host does not expose response headers'
                : ($refusals === 0
                    ? 'no refusals observed in ' . $windowMin . 'm'
                    : $misleading . ' of ' . $refusals
                        . ' refusals challenged a caller who had already sent a credential ('
                        . $sharePct . '%) — an observation, not a fault');
            // No score, deliberately: header PRESENCE cannot tell a wrongly
            // refused caller from a correctly refused one (an expired or
            // mistyped credential SHOULD get a 401 challenge), so this axis
            // reports what it observed and never grades it.
            //
            // Two DIFFERENT silences, and the rating now says which. A host
            // that cannot show response headers is 'not-available' (its
            // measurable:0 still rides along for a server that does not know
            // the label); everything else is 'not-scored' — permanently, by
            // design. Both used to be 'pending', the word for a score not
            // earned YET, which read to a careful reviewer as a meter that had
            // failed to finish.
            $rating = $measurable === 0 ? 'not-available' : 'not-scored';
            return ['score' => null, 'rating' => $rating, 'refusals' => $refusals,
                'challenged' => (int) ($s['challenged'] ?? 0),
                'credentialed' => (int) ($s['credentialed'] ?? 0),
                'credentialedChallenged' => $misleading, 'sharePct' => $sharePct,
                'windowMin' => $windowMin, 'measurable' => $measurable, 'caption' => $caption];
        }, null);
    }

    public static function setClockForTests(?callable $fn): void
    {
        self::$nowFn = $fn;
    }

    public static function resetForTests(): void
    {
        Store::delete(self::KEY);
        self::$nowFn = null;
    }
}