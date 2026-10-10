<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * Request-scoped database accounting.
 *
 * SQL and bindings are folded immediately into a 32-bit identity and discarded.
 * The issue token returned by issue() carries the request generation to the
 * completion site; completion never consults the then-current ambient request.
 */
final class DbWork
{
    private const KEY = 'reading:dbWork';
    private const MIN_REQUESTS = 5;
    private const WAVE_MAX_CALLS = 256;
    private const LARGE_RESULT_ROWS = 1000;
    private const MAX_IDENTITY_BYTES = 512;

    /** One greppable declaration of the client paths this kit can really hook. */
    public const WATCHED_DB_CLIENTS = ['PDO'];

    /** @var array<string,mixed>|null */
    private static ?array $request = null;
    private static int $generation = 0;
    /** @var array<string,bool> */
    private static array $watchedPdoDrivers = [];
    /** @var array<int,bool> */
    private static array $observedUnwatchedPdo = [];
    private static ?int $unwatchedForTests = null;

    private function __construct()
    {
    }

    public static function beginRequest(): void
    {
        self::$generation++;
        self::$request = [
            'generation' => self::$generation, 'nextCall' => 0, 'count' => 0,
            'ms' => 0.0, 'statements' => [], 'spans' => [], 'spansOver' => false,
            'rows' => [], 'rowsSum' => 0, 'rowsCalls' => 0, 'rowsWorst' => 0,
        ];
    }

    /**
     * Capture request scope and statement identity at ISSUE time.
     *
     * @param array<mixed> $arguments
     * @return array{generation:int,call:int,identity:int,start:float}|null
     */
    public static function issue(string $statement, array $arguments = []): ?array
    {
        return Safe::run(static function () use ($statement, $arguments): ?array {
            if (RuntimeFlags::disabled() || self::$request === null) {
                return null;
            }
            $identity = self::identity($statement, $arguments);
            $call = ++self::$request['nextCall'];
            return [
                'generation' => (int) self::$request['generation'],
                'call' => $call,
                'identity' => $identity,
                'start' => microtime(true) * 1000,
            ];
        }, null);
    }

    /** @param array{generation:int,call:int,identity:int,start:float}|null $token */
    public static function complete(?array $token, ?float $endedAtMs = null): void
    {
        Safe::fire(static function () use ($token, $endedAtMs): void {
            if ($token === null || self::$request === null
                || $token['generation'] !== self::$request['generation']) {
                return;
            }
            $end = $endedAtMs ?? microtime(true) * 1000;
            $start = is_finite($token['start']) ? $token['start'] : $end;
            $end = is_finite($end) && $end >= $start ? $end : $start;
            $ms = $end - $start;
            self::$request['count']++;
            self::$request['ms'] += $ms;
            $key = (string) $token['identity'];
            $old = self::$request['statements'][$key] ?? ['count' => 0, 'ms' => 0.0];
            $old['count']++;
            $old['ms'] += $ms;
            self::$request['statements'][$key] = $old;
            if (count(self::$request['spans']) >= self::WAVE_MAX_CALLS) {
                self::$request['spansOver'] = true;
            } elseif (!self::$request['spansOver']) {
                self::$request['spans'][] = [$start, $end, $token['call']];
            }
        });
    }

    /**
     * Mark a result size once it is actually known. Zero is a real size; null
     * is never passed here. Repeated updates replace, rather than duplicate,
     * the size for a call while a fetch loop is being drained.
     *
     * @param array{generation:int,call:int,identity:int,start:float}|null $token
     */
    public static function rows(?array $token, int $rows): void
    {
        Safe::fire(static function () use ($token, $rows): void {
            if ($token === null || $rows < 0 || self::$request === null
                || $token['generation'] !== self::$request['generation']) {
                return;
            }
            $key = (string) $token['call'];
            $prior = self::$request['rows'][$key] ?? null;
            if ($prior === null) {
                self::$request['rowsCalls']++;
            } else {
                self::$request['rowsSum'] -= $prior;
            }
            self::$request['rows'][$key] = $rows;
            self::$request['rowsSum'] += $rows;
            self::$request['rowsWorst'] = max(self::$request['rowsWorst'], $rows);
        });
    }

    /**
     * Add an observable result-drain duration to an already counted call.
     * This is used by PDO::query(), whose server round trip precedes statement
     * construction. It deliberately does not invent a round-trip span.
     *
     * @param array{generation:int,call:int,identity:int,start:float}|null $token
     */
    public static function addDrain(?array $token, float $durationMs): void
    {
        Safe::fire(static function () use ($token, $durationMs): void {
            if ($token === null || !is_finite($durationMs) || $durationMs <= 0
                || self::$request === null
                || $token['generation'] !== self::$request['generation']) {
                return;
            }
            self::$request['ms'] += $durationMs;
            $key = (string) $token['identity'];
            if (isset(self::$request['statements'][$key])) {
                self::$request['statements'][$key]['ms'] += $durationMs;
            }
        });
    }

    public static function endRequest(float $requestMs): void
    {
        $request = self::$request;
        self::$request = null;
        if ($request === null || $request['count'] === 0 || RuntimeFlags::disabled()) {
            return;
        }
        Safe::fire(static function () use ($request, $requestMs): void {
            $wall = is_finite($requestMs) ? max(0.0, $requestMs) : 0.0;
            $worst = 0;
            $redundant = 0.0;
            foreach ($request['statements'] as $statement) {
                $count = (int) $statement['count'];
                $worst = max($worst, $count);
                if ($count > 1) {
                    $redundant += ((float) $statement['ms'] / $count) * ($count - 1);
                }
            }
            $wave = !$request['spansOver'] ? self::sweep($request['spans']) : null;
            Store::update(self::KEY, static function ($cur) use (
                $request, $wall, $worst, $redundant, $wave
            ): array {
                $s = is_array($cur) ? $cur : [];
                $s['watchedRequests'] = (int) ($s['watchedRequests'] ?? 0) + 1;
                $s['watchedRequestMs'] = (float) ($s['watchedRequestMs'] ?? 0) + $wall;
                $s['dbMs'] = (float) ($s['dbMs'] ?? 0) + ($wall > 0 ? min($wall, $request['ms']) : 0);
                $s['callCount'] = (int) ($s['callCount'] ?? 0) + $request['count'];
                $s['repeatWorst'] = max((int) ($s['repeatWorst'] ?? 0), $worst);
                $s['rowsWorst'] = max((int) ($s['rowsWorst'] ?? 0), $request['rowsWorst']);
                $s['rowsSum'] = (int) ($s['rowsSum'] ?? 0) + $request['rowsSum'];
                $s['rowsCalls'] = (int) ($s['rowsCalls'] ?? 0) + $request['rowsCalls'];
                if ($worst > 1) {
                    $s['repeatRequests'] = (int) ($s['repeatRequests'] ?? 0) + 1;
                    $s['repeatMs'] = (float) ($s['repeatMs'] ?? 0)
                        + ($wall > 0 ? min($wall, $redundant) : 0);
                }
                if ($wave !== null) {
                    $s['waveRequests'] = (int) ($s['waveRequests'] ?? 0) + 1;
                    $s['wavesTotal'] = (int) ($s['wavesTotal'] ?? 0) + $wave['waves'];
                    $s['wavesWorst'] = max((int) ($s['wavesWorst'] ?? 0), $wave['waves']);
                    $s['waveSpanMs'] = (float) ($s['waveSpanMs'] ?? 0)
                        + ($wall > 0 ? min($wall, $wave['span']) : $wave['span']);
                    $s['waveSumMs'] = (float) ($s['waveSumMs'] ?? 0) + $wave['sum'];
                }
                return $s;
            }, []);
        });
    }

    /** Explicitly hand a PDO handle to the kit before preparing statements. */
    public static function watchPdo(\PDO $pdo): bool
    {
        return Safe::run(static function () use ($pdo): bool {
            $id = spl_object_id($pdo);
            $driver = strtolower((string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
            try {
                $existing = $pdo->getAttribute(\PDO::ATTR_STATEMENT_CLASS);
                if (is_array($existing) && isset($existing[0])
                    && $existing[0] !== 'PDOStatement'
                    && $existing[0] !== WatchedPdoStatement::class) {
                    self::$observedUnwatchedPdo[$id] = true;
                    return false;
                }
            } catch (\Throwable) {
                // Some PDO drivers cannot report the default statement class.
            }
            if (!$pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [WatchedPdoStatement::class, []])) {
                self::$observedUnwatchedPdo[$id] = true;
                return false;
            }
            self::$watchedPdoDrivers[$driver] = true;
            // Prepared execute() is fully timed. PDO::query() exposes only the
            // returned statement, after its round trip, so its drain is timed
            // and counted when touched; PDO::exec() exposes no hook at all.
            // Keep one positive partial-coverage blind spot for this handed
            // handle rather than claiming that all of its traffic is visible.
            self::$observedUnwatchedPdo[$id] = true;
            return true;
        }, false) ?? false;
    }

    /*
     * An existing PDO object cannot be converted into a PDO subclass: a
     * subclass must open a new connection from a DSN. A "decorating hand-over"
     * would therefore either cease to be type-compatible with PDO or reconnect
     * (changing transactions, attributes and host behaviour). We deliberately
     * do neither. Apps needing complete query()/exec() timing can construct
     * their own PDO subclass/decorator and file its prepared statements through
     * this hand-over; unavoidable native query()/exec() use remains counted by
     * the partial-coverage blind spot above.
     */

    /** @return array<string,mixed>|null */
    public static function work(): ?array
    {
        if (RuntimeFlags::disabled()) {
            return null;
        }
        $s = self::state();
        $watched = (int) ($s['watchedRequests'] ?? 0);
        $unwatched = self::unwatchedClients();
        if ($watched < self::MIN_REQUESTS) {
            return $unwatched === 0 ? null : [
                'score' => null, 'rating' => 'pending', 'measurable' => 0,
                'waitPct' => 0.0, 'callCount' => (int) ($s['callCount'] ?? 0),
                'watchedRequests' => $watched, 'hostedCalls' => 0,
                'repeatWorst' => null, 'repeatRequests' => (int) ($s['repeatRequests'] ?? 0),
                'repeatWastePct' => 0.0, 'unwatchedClients' => $unwatched,
            ];
        }
        $wait = self::pct((float) ($s['dbMs'] ?? 0), (float) ($s['watchedRequestMs'] ?? 0));
        $waste = self::pct((float) ($s['repeatMs'] ?? 0), (float) ($s['watchedRequestMs'] ?? 0));
        $worst = max(1, (int) ($s['repeatWorst'] ?? 0));
        $score = min(Score::scoreMetric($wait, 20, 70), Score::scoreMetric($worst, 1, 10));
        $axis = [
            'score' => $score, 'rating' => Score::getRating($score), 'measurable' => 1,
            'waitPct' => $wait, 'callCount' => (int) ($s['callCount'] ?? 0),
            'watchedRequests' => $watched, 'hostedCalls' => 0, 'repeatWorst' => $worst,
            'repeatRequests' => (int) ($s['repeatRequests'] ?? 0),
            'repeatWastePct' => $waste, 'unwatchedClients' => $unwatched,
        ];
        if ((int) ($s['rowsWorst'] ?? 0) >= self::LARGE_RESULT_ROWS) {
            $axis['rowsWorst'] = (int) $s['rowsWorst'];
        }
        return $axis;
    }

    /** @return array<string,mixed>|null */
    public static function sequencing(): ?array
    {
        if (RuntimeFlags::disabled()) {
            return null;
        }
        $s = self::state();
        $judged = max(0, (int) ($s['waveRequests'] ?? 0));
        $unwatched = self::unwatchedClients();
        if ($judged < self::MIN_REQUESTS) {
            return $unwatched === 0 ? null : [
                'score' => null, 'rating' => 'pending', 'measurable' => 0,
                'wavesWorst' => null, 'wavesAvg' => 0.0, 'seriesPct' => 0,
                'watchedRequests' => $judged, 'unwatchedClients' => $unwatched,
            ];
        }
        $worst = max(1, (int) ($s['wavesWorst'] ?? 0));
        $sum = (float) ($s['waveSumMs'] ?? 0);
        $series = $sum > 0
            ? max(0, min(100, (int) round(100 * (float) ($s['waveSpanMs'] ?? 0) / $sum)))
            : 100;
        $score = Score::scoreMetric($worst, 2, 10);
        return [
            'score' => $score, 'rating' => Score::getRating($score), 'measurable' => 1,
            'wavesWorst' => $worst,
            'wavesAvg' => round(((int) ($s['wavesTotal'] ?? 0) / $judged) * 10) / 10.0,
            'seriesPct' => $series, 'watchedRequests' => $judged,
            'unwatchedClients' => $unwatched,
        ];
    }

    /** @return array<string,mixed>|null */
    public static function rowVolume(): ?array
    {
        if (RuntimeFlags::disabled()) {
            return null;
        }
        $s = self::state();
        $watched = max(0, (int) ($s['watchedRequests'] ?? 0));
        $sized = max(0, (int) ($s['rowsCalls'] ?? 0));
        $unwatched = self::unwatchedClients();
        if ($watched < self::MIN_REQUESTS || $sized === 0) {
            return $unwatched === 0 ? null : [
                'score' => null, 'rating' => 'pending', 'measurable' => 0,
                'worstRows' => null, 'rowsAvg' => 0, 'sizedCalls' => $sized,
                'watchedRequests' => $watched, 'unwatchedClients' => $unwatched,
            ];
        }
        $worst = max(0, (int) ($s['rowsWorst'] ?? 0));
        $avg = max(0, (int) round((int) ($s['rowsSum'] ?? 0) / $sized));
        $score = min(
            Score::scoreMetric($worst, 500, 10000),
            Score::scoreMetric($avg, 100, 2000)
        );
        return [
            'score' => $score, 'rating' => Score::getRating($score), 'measurable' => 1,
            'worstRows' => $worst, 'rowsAvg' => $avg, 'sizedCalls' => $sized,
            'watchedRequests' => $watched, 'unwatchedClients' => $unwatched,
        ];
    }

    /**
     * PHP-FPM and mod_php have request-local connections, not a readable pool.
     * Swoole, RoadRunner and FrankenPHP may host application-specific pools,
     * but PHP exposes no common occupancy API for them. Until a concrete pool
     * object is explicitly handed over, emitting size=1 would be fabrication.
     *
     * @return array<string,mixed>|null
     */
    public static function poolPressure(): ?array
    {
        return null;
    }

    public static function clear(): void
    {
        self::$request = null;
        Store::delete(self::KEY);
    }

    public static function resetForTests(): void
    {
        self::clear();
        self::$watchedPdoDrivers = [];
        self::$observedUnwatchedPdo = [];
        self::$unwatchedForTests = null;
    }

    public static function setUnwatchedClientsForTests(?int $count): void
    {
        self::$unwatchedForTests = $count;
    }

    /** @return array<string,mixed> */
    private static function state(): array
    {
        $state = Store::get(self::KEY, []);
        return is_array($state) ? $state : [];
    }

    private static function unwatchedClients(): int
    {
        if (self::$unwatchedForTests !== null) {
            return max(0, self::$unwatchedForTests);
        }
        $count = count(self::$observedUnwatchedPdo);
        foreach (\PDO::getAvailableDrivers() as $driver) {
            if (!isset(self::$watchedPdoDrivers[strtolower($driver)])) {
                $count++;
            }
        }
        if (extension_loaded('mysqli')) {
            $count++;
        }
        if (extension_loaded('pgsql')) {
            $count++;
        }
        foreach ([
            'Doctrine\\DBAL\\Connection',
            'Illuminate\\Database\\Connection',
        ] as $class) {
            if (class_exists($class, false)) {
                $count++;
            }
        }
        return $count;
    }

    /** @param array<mixed> $arguments */
    private static function identity(string $statement, array $arguments): int
    {
        $text = substr($statement, 0, self::MAX_IDENTITY_BYTES);
        foreach (array_slice($arguments, 0, 32, true) as $key => $value) {
            $text .= '|' . gettype($key) . ':' . (string) $key . '=';
            if (is_scalar($value) || $value === null) {
                $text .= gettype($value) . ':' . substr((string) $value, 0, 96);
            } else {
                $text .= get_debug_type($value);
            }
            if (strlen($text) >= self::MAX_IDENTITY_BYTES) {
                break;
            }
        }
        $hash = crc32(substr($text, 0, self::MAX_IDENTITY_BYTES));
        unset($text);
        return (int) sprintf('%u', $hash);
    }

    /** @param list<array{0:float,1:float,2:int}> $spans
     *  @return array{waves:int,span:float,sum:float}
     */
    private static function sweep(array $spans): array
    {
        usort($spans, static fn (array $a, array $b): int =>
            $a[0] <=> $b[0] ?: $a[1] <=> $b[1] ?: $a[2] <=> $b[2]);
        $waves = 0;
        $span = $sum = 0.0;
        $segmentStart = $segmentEnd = null;
        foreach ($spans as [$start, $end]) {
            $sum += $end - $start;
            if ($segmentStart === null) {
                $waves++;
                $segmentStart = $start;
                $segmentEnd = $end;
            } elseif ($start < $segmentEnd) {
                $segmentEnd = max($segmentEnd, $end);
            } else {
                $span += $segmentEnd - $segmentStart;
                $waves++;
                $segmentStart = $start;
                $segmentEnd = $end;
            }
        }
        if ($segmentStart !== null) {
            $span += $segmentEnd - $segmentStart;
        }
        return ['waves' => $waves, 'span' => $span, 'sum' => $sum];
    }

    private static function pct(float $part, float $whole): float
    {
        return $whole > 0
            ? round(max(0, min(100, 100 * $part / $whole)) * 10) / 10.0
            : 0.0;
    }
}