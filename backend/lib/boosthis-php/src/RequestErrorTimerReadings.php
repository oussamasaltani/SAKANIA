<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * Request-path, error and timer readings whose only honest PHP window is the
 * request currently passing through the mounted middleware.
 *
 * PHP does not keep a world alive after a response. Rates therefore use the
 * completed request's own elapsed window, as required by the family contract;
 * they are never extrapolated from a shorter cross-request approximation.
 * Timer health is explicitly declined: a timer cannot survive into a second
 * PHP request, so there is no growth series to measure.
 */
final class RequestErrorTimerReadings
{
    /** Shared closed reason code: the runtime/platform does not expose it. */
    public const REASON_PLATFORM_DOES_NOT_EXPOSE = 3;

    private const KEY_LATEST = 'request_family:latest';
    private const KEY_ROUTES = 'request_family:routes';
    private const KEY_CACHE = 'request_family:cache';
    private const ROUTE_CAP = 100;
    private const CACHE_MIN = 5;
    private const CONNECTION_MIN = 20;

    /** @var array{startedAt:float,outbound:int,connects:int,unhandled:int}|null */
    private static ?array $request = null;

    private function __construct()
    {
    }

    public static function beginRequest(): void
    {
        self::$request = [
            'startedAt' => microtime(true),
            'outbound' => 0,
            'connects' => 0,
            'unhandled' => 0,
        ];
    }

    public static function noteOutbound(string $url, bool $newConnection): void
    {
        if (self::$request === null || RuntimeFlags::disabled()) {
            return;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (is_string($host)) {
            $host = strtolower(trim($host, '.'));
            if ($host === 'boosthis.com' || str_ends_with($host, '.boosthis.com')) {
                return;
            }
        }
        self::$request['outbound']++;
        self::$request['connects'] += $newConnection ? 1 : 0;
    }

    /** Record one dependency cache verdict from fixed, code-defined headers. */
    public static function noteUpstreamCache(array $headers): void
    {
        if (RuntimeFlags::disabled()) {
            return;
        }
        $lower = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || (!is_string($value) && !is_array($value))) {
                continue;
            }
            $key = strtolower($name);
            if (!in_array($key, ['x-vercel-cache', 'cf-cache-status', 'x-nextjs-cache', 'cache-status', 'x-cache'], true)) {
                continue;
            }
            $raw = is_array($value) ? ($value[0] ?? null) : $value;
            if (is_string($raw) && strlen($raw) <= 120) {
                $lower[$key] = trim($raw);
            }
        }
        $verdict = self::cacheVerdict($lower);
        if ($verdict === null) {
            return;
        }
        Store::update(self::KEY_CACHE, static function ($cur) use ($verdict): array {
            $s = is_array($cur) ? $cur : [];
            foreach (['hits', 'misses', 'stale', 'bypass'] as $key) {
                $s[$key] = max(0, (int) ($s[$key] ?? 0));
            }
            $s[$verdict]++;
            return $s;
        }, []);
    }

    /** Middleware exception path: this throwable escaped the whole app stack. */
    public static function noteUnhandled(): void
    {
        if (self::$request !== null) {
            self::$request['unhandled']++;
        }
    }

    /**
     * Bank the one completed request. This runs before PHP hands the response
     * back and before DependencyWork clears its request scope.
     */
    public static function endRequest(int $status, string $route, int $dataCalls, int $dataFailures): void
    {
        $request = self::$request;
        self::$request = null;
        if ($request === null || RuntimeFlags::disabled()) {
            return;
        }
        $elapsedMs = max(0.001, (microtime(true) - $request['startedAt']) * 1000.0);
        $failedResponse = $status >= 500;

        self::recordRoute($route, $failedResponse);

        $contained = $collapsed = $allFailed = $unattributed = 0;
        if ($dataCalls > 0) {
            if ($dataFailures <= 0) {
                $unattributed = $failedResponse ? 1 : 0;
            } elseif (!$failedResponse) {
                $contained = 1;
            } elseif ($dataFailures >= $dataCalls) {
                $allFailed = 1;
            } else {
                $collapsed = 1;
            }
        }

        $limitSec = (int) (@ini_get('max_execution_time') ?: 0);
        $latest = [
            'windowMin' => $elapsedMs / 60000.0,
            'unhandled' => $request['unhandled'],
            'outbound' => $request['outbound'],
            'connects' => $request['connects'],
            'watchedRequests' => $dataCalls > 0 ? 1 : 0,
            'contained' => $contained,
            'collapsed' => $collapsed,
            'allFailed' => $allFailed,
            'unattributed' => $unattributed,
            'dataFailures' => $dataFailures,
            'elapsedMs' => $elapsedMs,
            'limitMs' => max(0, $limitSec * 1000),
        ];
        Store::set(self::KEY_LATEST, $latest);
    }

    /** @param array<string,mixed> $axes */
    public static function addAxes(array &$axes): void
    {
        if (RuntimeFlags::disabled()) {
            return;
        }

        // PHP's response abstractions expose a completed body, not whether the
        // web-server-owned socket's write buffer filled.
        $axes['backpressure'] = self::declined();
        // PHP has no engine counter for every raised (including caught) exception.
        $axes['exceptionChurn'] = self::declined();
        // PHP tears timers down with the request. This is an explicit refusal,
        // never a synthetic zero and never a one-request "growth" number.
        $axes['timerHealth'] = [
            'score' => null,
            'rating' => 'pending',
            'measurable' => 0,
            'reasonCode' => self::REASON_PLATFORM_DOES_NOT_EXPOSE,
            'timers' => null,
            'growthPerMin' => null,
            'sampleCount' => 0,
            'unwatchedTimers' => 1,
        ];
        // PHP exposes no monotonic process-uptime clock that survives requests.
        $axes['uptimeStability'] = self::declined();

        $latest = Store::get(self::KEY_LATEST, null);
        if (is_array($latest)) {
            self::addConnectionSetup($axes, $latest);
            self::addTimeoutHeadroom($axes, $latest);
            self::addUnhandledErrors($axes, $latest);
            self::addFailureContainment($axes, $latest);
        }
        self::addRouteFailures($axes);
        self::addUpstreamCache($axes);
    }

    /** @return array<string,mixed> */
    private static function declined(): array
    {
        return [
            'score' => null,
            'rating' => 'pending',
            'measurable' => 0,
            'reasonCode' => self::REASON_PLATFORM_DOES_NOT_EXPOSE,
        ];
    }

    private static function addConnectionSetup(array &$axes, array $s): void
    {
        $attempts = (int) ($s['outbound'] ?? 0);
        if ($attempts < self::CONNECTION_MIN) {
            return;
        }
        $connects = min($attempts, max(0, (int) ($s['connects'] ?? 0)));
        $pct = round((1000.0 * $connects / $attempts)) / 10.0;
        $score = Score::scoreMetric($pct, 10.0, 60.0);
        $axes['connectionSetup'] = [
            'reconnectPct' => $pct,
            'connectCount' => $connects,
            'attemptCount' => $attempts,
            'score' => $score,
            'rating' => Score::getRating($score),
        ];
    }

    private static function addTimeoutHeadroom(array &$axes, array $s): void
    {
        $limitMs = (int) ($s['limitMs'] ?? 0);
        if ($limitMs <= 0) {
            $axes['timeoutHeadroom'] = self::declined() + ['hostKind' => 'php', 'runs' => 1];
            return;
        }
        $elapsed = max(0.0, (float) ($s['elapsedMs'] ?? 0.0));
        $used = round((1000.0 * $elapsed / $limitMs)) / 10.0;
        $score = Score::scoreMetric($used, 40.0, 90.0);
        $axes['timeoutHeadroom'] = [
            'usedPct' => $used,
            'p50UsedPct' => $used,
            'p95UsedPct' => $used,
            'remainingMs' => max(0, (int) round($limitMs - $elapsed)),
            'limitMs' => $limitMs,
            'runs' => 1,
            'hostKind' => 'php',
            'measurable' => 1,
            'score' => $score,
            'rating' => Score::getRating($score),
        ];
    }

    private static function addUnhandledErrors(array &$axes, array $s): void
    {
        $count = max(0, (int) ($s['unhandled'] ?? 0));
        $windowMin = max(0.000001, (float) ($s['windowMin'] ?? 0.0));
        $perHour = round(($count / $windowMin * 60.0) * 10) / 10.0;
        $score = Score::scoreMetric($perHour, 0.0, 5.0);
        $axes['unhandledErrors'] = [
            'count' => $count,
            'windowMin' => round($windowMin * 1000) / 1000.0,
            'perHour' => $perHour,
            'score' => $score,
            'rating' => Score::getRating($score),
        ];
    }

    private static function addFailureContainment(array &$axes, array $s): void
    {
        $watched = (int) ($s['watchedRequests'] ?? 0);
        if ($watched <= 0) {
            return;
        }
        $collapsed = (int) ($s['collapsed'] ?? 0);
        $contained = (int) ($s['contained'] ?? 0);
        $allFailed = (int) ($s['allFailed'] ?? 0);
        $unattributed = (int) ($s['unattributed'] ?? 0);
        $judged = $collapsed + $contained;
        if ($judged < 3) {
            $axes['failureContainment'] = [
                'measurable' => 1,
                'collapsedCount' => $collapsed,
                'containedCount' => $contained,
                'allFailedCount' => $allFailed,
                'unattributedCount' => $unattributed,
                'watchedRequests' => $watched,
                'collapsePct' => null,
                'score' => null,
                'rating' => 'pending',
                'reasonCode' => 6,
            ];
            return;
        }
        $pct = round((1000.0 * $collapsed / $judged)) / 10.0;
        $score = Score::scoreMetric($pct, 5.0, 50.0);
        $axes['failureContainment'] = [
            'measurable' => 1,
            'collapsedCount' => $collapsed,
            'containedCount' => $contained,
            'allFailedCount' => $allFailed,
            'unattributedCount' => $unattributed,
            'watchedRequests' => $watched,
            'collapsePct' => $pct,
            'score' => $score,
            'rating' => Score::getRating($score),
        ];
    }

    private static function recordRoute(string $route, bool $failed): void
    {
        Store::update(self::KEY_ROUTES, static function ($cur) use ($route, $failed): array {
            $s = is_array($cur) ? $cur : [];
            $parts = is_array($s['parts'] ?? null) ? $s['parts'] : [];
            $untracked = max(0, (int) ($s['untracked'] ?? 0));
            $key = hash('sha256', $route);
            if (!isset($parts[$key]) && count($parts) >= self::ROUTE_CAP) {
                $untracked++;
            } else {
                $p = is_array($parts[$key] ?? null) ? $parts[$key] : ['observed' => 0, 'failed' => 0];
                $p['observed']++;
                $p['failed'] += $failed ? 1 : 0;
                $parts[$key] = $p;
            }
            return ['parts' => $parts, 'untracked' => $untracked];
        }, []);
    }

    private static function addRouteFailures(array &$axes): void
    {
        $s = Store::get(self::KEY_ROUTES, null);
        if (!is_array($s)) {
            return;
        }
        $parts = is_array($s['parts'] ?? null) ? $s['parts'] : [];
        $untracked = (int) ($s['untracked'] ?? 0);
        if ($parts === [] && $untracked <= 0) {
            return;
        }
        $observed = $failed = 0;
        foreach ($parts as $part) {
            if (is_array($part)) {
                $observed += (int) ($part['observed'] ?? 0);
                $failed += (int) ($part['failed'] ?? 0);
            }
        }
        $axes['routeFailures'] = [
            'score' => null,
            'rating' => 'not-scored',
            'parts' => count($parts),
            'observed' => $observed,
            'failed' => $failed,
            'untracked' => $untracked,
        ];
    }

    private static function addUpstreamCache(array &$axes): void
    {
        $s = Store::get(self::KEY_CACHE, null);
        if (!is_array($s)) {
            return;
        }
        $hits = (int) ($s['hits'] ?? 0);
        $misses = (int) ($s['misses'] ?? 0);
        $stale = (int) ($s['stale'] ?? 0);
        $bypass = (int) ($s['bypass'] ?? 0);
        $cacheable = $hits + $misses + $stale;
        $checked = $cacheable + $bypass;
        if ($checked < self::CACHE_MIN || $cacheable <= 0) {
            return;
        }
        $pct = round(100.0 * ($hits + $stale) / $cacheable);
        $score = Score::scoreMetric(100.0 - $pct, 10.0, 60.0);
        $axes['upstreamCache'] = [
            'hitPct' => $pct,
            'checked' => $checked,
            'hits' => $hits,
            'misses' => $misses,
            'stale' => $stale,
            'bypass' => $bypass,
            'score' => $score,
            'rating' => Score::getRating($score),
        ];
    }

    /** @param array<string,string> $h */
    private static function cacheVerdict(array $h): ?string
    {
        foreach (['x-vercel-cache', 'cf-cache-status', 'x-nextjs-cache', 'cache-status', 'x-cache'] as $name) {
            if (!isset($h[$name])) {
                continue;
            }
            $raw = strtoupper(trim($h[$name]));
            if ($name === 'cache-status') {
                $first = strtolower(explode(',', $h[$name])[0]);
                if (preg_match('/(?:^|;)\s*hit(?:;|$)/', $first)) {
                    return 'hits';
                }
                if (str_contains($first, 'fwd=stale') || str_contains($first, 'fwd=request')) {
                    return 'stale';
                }
                if (str_contains($first, 'fwd=bypass') || str_contains($first, 'fwd=method')) {
                    return 'bypass';
                }
                if (str_contains($first, 'fwd=miss') || str_contains($first, 'fwd=uri-miss')
                    || str_contains($first, 'fwd=vary-miss')) {
                    return 'misses';
                }
                continue;
            }
            $word = preg_split('/[\s,]+/', $raw)[0] ?? '';
            if (in_array($word, ['HIT', 'PRERENDER'], true)) {
                return 'hits';
            }
            if (in_array($word, ['STALE', 'EXPIRED', 'UPDATING', 'REVALIDATED', 'REFRESHHIT'], true)) {
                return 'stale';
            }
            if ($word === 'MISS') {
                return 'misses';
            }
            if (in_array($word, ['BYPASS', 'DYNAMIC', 'IGNORED', 'ERROR'], true)) {
                return 'bypass';
            }
        }
        return null;
    }

    public static function clear(): void
    {
        self::$request = null;
        Store::delete(self::KEY_LATEST);
        Store::delete(self::KEY_ROUTES);
        Store::delete(self::KEY_CACHE);
    }

    /** Test-only direct request completion without sleeping. */
    public static function setLatestForTests(array $latest): void
    {
        Store::set(self::KEY_LATEST, $latest);
    }
}