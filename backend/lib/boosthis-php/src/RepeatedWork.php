<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * Repeated-work detector: did this app make the same identifiable call twice
 * inside one request?
 *
 * Only calls whose inputs are visible are watched: Boosthis::trackCall() sees
 * the call name and arguments, and the explicit outbound feed sees a full URL.
 * The label-only trackPerf() API is deliberately not watched because equal
 * labels can describe different work; bare outbound hostnames are refused for
 * the same reason.
 *
 * Identities are bounded, locally hashed, and immediately discarded. No name,
 * URL, host, argument, or hash is stored or uploaded — only aggregate counts
 * and durations leave this class. If no identifiable call is seen, the meter
 * remains absent: "cannot tell", never an unjustified zero.
 */
final class RepeatedWork
{
    public const MAX_DISTINCT_CALLS = 512;
    public const MAX_ARGS = 8;
    public const MAX_DEPTH = 2;
    public const MAX_MEMBERS = 12;
    public const MAX_STRING = 64;
    public const MAX_IDENTITY_CHARS = 512;

    /**
     * Session totals live in the shared Store, never in class statics. PHP
     * throws userland state away at the end of every request, so statics can
     * only ever hold ONE request — the five-watched-request floor would never
     * be reached in a real app and the axis would be permanently absent, which
     * reads as "cannot tell" when the truth is "we measured and forgot".
     * Per-REQUEST state below is a different thing and rightly stays in memory.
     */
    private const KEY = 'repeated_work';

    /** @var array<int,array{count:int,totalMs:float}>|null */
    private static ?array $calls = null;
    private static int $nextToken = 0;
    private static ?int $activeToken = null;

    /** @var array{identity:int,start:float}|null */
    private static ?array $pendingOutbound = null;

    private function __construct()
    {
    }

    /** @param mixed $value */
    private static function renderValue($value, int $depth): string
    {
        if ($value === null) {
            return 'z';
        }
        if (is_string($value)) {
            return strlen($value) > self::MAX_STRING
                ? 's' . strlen($value) . ':' . substr($value, 0, self::MAX_STRING)
                : 's:' . $value;
        }
        if (is_bool($value)) {
            return 'b:' . ($value ? 'true' : 'false');
        }
        if (is_int($value) || is_float($value)) {
            return 'n:' . (string) $value;
        }
        if (is_callable($value)) {
            return 'f';
        }
        if ($depth <= 0) {
            return 'o';
        }

        try {
            if (is_array($value)) {
                if (array_is_list($value)) {
                    $parts = [];
                    foreach (array_slice($value, 0, self::MAX_MEMBERS) as $member) {
                        $parts[] = self::renderValue($member, $depth - 1);
                    }

                    return '[' . count($value) . ':' . implode(',', $parts) . ']';
                }
                $keys = array_map('strval', array_keys($value));
                sort($keys, SORT_STRING);
                $parts = [];
                foreach (array_slice($keys, 0, self::MAX_MEMBERS) as $key) {
                    $member = array_key_exists($key, $value) ? $value[$key] : null;
                    $parts[] = $key . '=' . self::renderValue($member, $depth - 1);
                }

                return '{' . implode(',', $parts) . '}';
            }
            if (is_object($value)) {
                $members = get_object_vars($value);
                $keys = array_keys($members);
                sort($keys, SORT_STRING);
                $parts = [];
                foreach (array_slice($keys, 0, self::MAX_MEMBERS) as $key) {
                    $parts[] = $key . '=' . self::renderValue($members[$key], $depth - 1);
                }

                return '{' . implode(',', $parts) . '}';
            }
        } catch (\Throwable) {
            return 'o';
        }

        return 'u';
    }

    private static function fnv1a(string $value): int
    {
        $hash = 0x811c9dc5;
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $hash ^= ord($value[$i]);
            $hash = ($hash * 0x01000193) & 0xffffffff;
        }

        return $hash;
    }

    /**
     * @param list<mixed> $args
     */
    public static function callIdentity(string $target, array $args = []): int
    {
        return (int) Safe::run(static function () use ($target, $args): int {
            $rendered = $target;
            $values = array_values($args);
            $shown = min(count($values), self::MAX_ARGS);
            for ($i = 0; $i < $shown; $i++) {
                $rendered .= '|' . self::renderValue($values[$i], self::MAX_DEPTH);
                if (strlen($rendered) > self::MAX_IDENTITY_CHARS) {
                    $rendered = substr($rendered, 0, self::MAX_IDENTITY_CHARS);
                    break;
                }
            }
            if (count($values) > $shown) {
                $rendered .= '|+' . count($values);
            }

            return self::fnv1a($rendered);
        }, self::fnv1a($target));
    }

    public static function noteWatchedCall(int $identity, float $durationMs): void
    {
        if (RuntimeFlags::disabled() || self::$calls === null) {
            return;
        }
        Safe::fire(static function () use ($identity, $durationMs): void {
            $ms = is_finite($durationMs) && $durationMs > 0 ? $durationMs : 0.0;
            if (isset(self::$calls[$identity])) {
                self::$calls[$identity]['count']++;
                self::$calls[$identity]['totalMs'] += $ms;

                return;
            }
            if (count(self::$calls) >= self::MAX_DISTINCT_CALLS) {
                return;
            }
            self::$calls[$identity] = ['count' => 1, 'totalMs' => $ms];
        });
    }

    /** A full-URL outbound call began. Bare hostnames are deliberately refused. */
    public static function noteOutboundStart(?string $target): void
    {
        if (RuntimeFlags::disabled() || self::$calls === null
            || $target === null || !str_contains($target, '://')) {
            return;
        }
        Safe::fire(static function () use ($target): void {
            self::$pendingOutbound = [
                'identity' => self::callIdentity($target),
                'start' => microtime(true),
            ];
        });
    }

    /** Clear an unpaired marker before an attempt that cannot be identified. */
    public static function discardPendingOutbound(): void
    {
        self::$pendingOutbound = null;
    }

    /** File the pending outbound call on success or failure, then clear it. */
    public static function noteOutboundEnd(?float $durationMs = null): void
    {
        $pending = self::$pendingOutbound;
        self::$pendingOutbound = null;
        if ($pending === null || RuntimeFlags::disabled()) {
            return;
        }
        $elapsed = $durationMs ?? ((microtime(true) - $pending['start']) * 1000);
        self::noteWatchedCall($pending['identity'], $elapsed);
    }

    /** Open the process-local scope for one PHP request. */
    public static function beginRequestWork(): ?int
    {
        if (RuntimeFlags::disabled()) {
            return null;
        }
        self::$nextToken++;
        self::$activeToken = self::$nextToken;
        self::$calls = [];
        self::$pendingOutbound = null;

        return self::$activeToken;
    }

    /** Fold one request into the process-global session totals. */
    public static function endRequestWork(?int $token, float $requestMs): void
    {
        if ($token === null || self::$activeToken !== $token || self::$calls === null) {
            return;
        }
        $calls = self::$calls;
        self::$calls = null;
        self::$activeToken = null;
        self::$pendingOutbound = null;

        Safe::fire(static function () use ($calls, $requestMs): void {
            $identified = 0;
            $worst = 0;
            $redundant = 0.0;
            foreach ($calls as $tally) {
                $count = $tally['count'];
                $identified += $count;
                $worst = max($worst, $count);
                if ($count > 1) {
                    $redundant += ($tally['totalMs'] / $count) * ($count - 1);
                }
            }
            if ($identified === 0 || RuntimeFlags::disabled()) {
                return;
            }
            $wall = is_finite($requestMs) && $requestMs > 0 ? $requestMs : 0.0;
            Store::update(self::KEY, static function ($cur) use ($worst, $wall, $redundant): array {
                $s = self::normalize($cur);
                $s['watchedRequests']++;
                $s['watchedRequestMs'] += $wall;
                $s['worstRepeats'] = max($s['worstRepeats'], $worst);
                if ($worst > 1) {
                    $s['requestsWithRepeat']++;
                    $s['redundantMs'] += min($redundant, $wall);
                }

                return $s;
            }, null);
        });
    }

    /**
     * Coerce whatever came back from the Store into the totals shape. Corrupt
     * or absent state is treated as a fresh session, never as a reason to throw.
     *
     * @param mixed $cur
     * @return array{watchedRequests:int,requestsWithRepeat:int,worstRepeats:int,redundantMs:float,watchedRequestMs:float}
     */
    private static function normalize($cur): array
    {
        $s = is_array($cur) ? $cur : [];

        return [
            'watchedRequests' => (int) ($s['watchedRequests'] ?? 0),
            'requestsWithRepeat' => (int) ($s['requestsWithRepeat'] ?? 0),
            'worstRepeats' => (int) ($s['worstRepeats'] ?? 0),
            'redundantMs' => (float) ($s['redundantMs'] ?? 0.0),
            'watchedRequestMs' => (float) ($s['watchedRequestMs'] ?? 0.0),
        ];
    }

    /** @return array{watchedRequests:int,requestsWithRepeat:int,worstRepeats:int,redundantMs:float,watchedRequestMs:float} */
    public static function stats(): array
    {
        return Safe::run(
            static fn (): array => self::normalize(Store::get(self::KEY, null)),
            self::normalize(null),
        );
    }

    /** Clear request and session state. Idempotent. */
    public static function clear(): void
    {
        self::$calls = null;
        self::$activeToken = null;
        self::$pendingOutbound = null;
        Store::delete(self::KEY);
    }
}