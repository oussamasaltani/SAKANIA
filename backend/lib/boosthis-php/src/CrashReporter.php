<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * Crash fingerprints.
 *
 * A crash becomes four small facts: the error type, a hash that groups
 * identical crashes, the top stack frame with paths reduced to basenames, and
 * how many times it happened. No message, no source, no user data. The
 * signature basis is code-defined ONLY — the redacted frame, never the raw
 * message, because a message can contain anything.
 *
 * GUEST SAFETY IS ABSOLUTE. This class watches; it never intervenes. The
 * exception handler chains to whatever the host had installed, and when the
 * host had nothing, the original exception is re-thrown so PHP's own fatal path
 * runs exactly as it would have without us. A crash the host would have shown
 * is still shown.
 */
final class CrashReporter
{
    public const MAX_OCCURRENCES = 100000;
    public const MAX_BATCH = 50;
    public const MAX_CRASHES = 200;

    private const KEY = 'crashes';

    private static bool $installed = false;
    private static bool $active = false;
    /** @var callable|null */
    private static $prevExceptionHandler = null;

    private function __construct()
    {
    }

    /** Install the chained handlers. Idempotent; never throws. */
    public static function install(): void
    {
        if (self::$installed || RuntimeFlags::disabled()) {
            return;
        }
        self::$installed = true;
        self::$active = true;
        Safe::fire(static function (): void {
            self::$prevExceptionHandler = set_exception_handler(
                static function (\Throwable $e): void {
                    RequestErrorTimerReadings::noteUnhandled();
                    Safe::fire(static fn () => self::capture($e, 'uncaught'));
                    Safe::fire(static fn () => self::flushPending());
                    $prev = self::$prevExceptionHandler;
                    if (is_callable($prev)) {
                        // The host had its own handler: hand the crash on
                        // untouched and let it decide what the visitor sees.
                        $prev($e);

                        return;
                    }
                    // Nothing was installed before us, so PHP itself would have
                    // reported this. Re-throw so it still does.
                    throw $e;
                }
            );
            @register_shutdown_function(static function (): void {
                Safe::fire(static function (): void {
                    $last = error_get_last();
                    if ($last === null) {
                        return;
                    }
                    $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR
                        | E_USER_ERROR | E_RECOVERABLE_ERROR;
                    if ((($last['type'] ?? 0) & $fatal) === 0) {
                        return;
                    }
                    RequestErrorTimerReadings::noteUnhandled();
                    self::captureFatal(
                        (int) ($last['type'] ?? 0),
                        (string) ($last['file'] ?? ''),
                        (int) ($last['line'] ?? 0)
                    );
                    self::flushPending();
                });
            });
        });
    }

    /** Record one crash from a thrown object. */
    public static function capture(\Throwable $e, string $kind = 'uncaught'): void
    {
        if (!self::$active || RuntimeFlags::disabled()) {
            return;
        }
        $r = self::withCurrentAction(self::redactError($e, $kind));
        self::store($r);
    }

    /**
     * Record a fatal that PHP reported at shutdown. There is no throwable, so
     * the frame is built from the error's own file and line.
     */
    public static function captureFatal(int $level, string $file, int $line): void
    {
        if (!self::$active || RuntimeFlags::disabled()) {
            return;
        }
        $errorName = self::sanitizeErrorName(self::levelName($level));
        $frame = self::formatRedactedFrame('<fatal>', $file, $line);
        self::store(self::withCurrentAction([
            'signature' => self::truncate($errorName . ':' . self::hash($frame), 120),
            'errorName' => $errorName,
            'kind' => 'uncaught',
            'redactedFrame' => $frame,
        ]));
    }

    /**
     * Reduce a throwable to the four transmittable facts.
     *
     * @return array{signature:string,errorName:string,kind:string,redactedFrame:string}
     */
    public static function redactError(\Throwable $e, string $kind): array
    {
        $short = self::shortClassName(get_class($e));
        $errorName = self::sanitizeErrorName($short);
        $trace = $e->getTrace();
        $top = is_array($trace) && isset($trace[0]) && is_array($trace[0]) ? $trace[0] : null;
        $func = '<unknown>';
        if ($top !== null) {
            $class = isset($top['class']) && is_string($top['class']) ? $top['class'] : '';
            $fn = isset($top['function']) && is_string($top['function']) ? $top['function'] : '';
            $func = $class !== '' ? $class . '.' . $fn : $fn;
        }
        $file = $top['file'] ?? $e->getFile();
        $line = $top['line'] ?? $e->getLine();
        $redactedFrame = self::formatRedactedFrame(
            $func,
            is_string($file) ? $file : '',
            is_int($line) ? $line : 0
        );
        // Basis is the frame when we have one, else a constant marker — never
        // the exception's own message.
        $basis = $top !== null || $redactedFrame !== '<unknown>'
            ? $redactedFrame
            : $errorName . '|' . $kind . '|<no-frame>';

        return [
            'signature' => self::truncate($errorName . ':' . self::hash($basis), 120),
            'errorName' => $errorName,
            'kind' => self::normalizeKind($kind),
            'redactedFrame' => $redactedFrame,
        ];
    }

    /**
     * Add the action held at this exact capture point. Missing scope adds
     * nothing: absence reads as "no action recorded", never as a guessed action.
     *
     * @param array{signature:string,errorName:string,kind:string,redactedFrame:string} $r
     * @return array{signature:string,errorName:string,kind:string,redactedFrame:string,traceId?:string,spanId?:string}
     */
    private static function withCurrentAction(array $r): array
    {
        try {
            $action = SpanScope::currentAction();
            if ($action !== null) {
                $r['traceId'] = $action['traceId'];
                $r['spanId'] = $action['spanId'];
            }
        } catch (\Throwable $e) {
            // A crash reporter that cannot read scope sends less; it never
            // turns its own bookkeeping into a second crash.
        }

        return $r;
    }

    /** @param array{signature:string,errorName:string,kind:string,redactedFrame:string,traceId?:string,spanId?:string} $r */
    private static function store(array $r): void
    {
        Safe::fire(static function () use ($r): void {
            $now = Telemetry::nowMs();
            Store::update(self::KEY, static function ($cur) use ($r, $now) {
                $pending = is_array($cur) ? $cur : [];
                $sig = $r['signature'];
                if (isset($pending[$sig]) && is_array($pending[$sig])) {
                    $entry = $pending[$sig];
                    $entry['sessionTotal'] = min(
                        (int) ($entry['sessionTotal'] ?? 0) + 1,
                        self::MAX_OCCURRENCES
                    );
                    $entry['unsent'] = min(
                        (int) ($entry['unsent'] ?? 0) + 1,
                        self::MAX_OCCURRENCES
                    );
                    $entry['kind'] = $r['kind'];
                    $entry['errorName'] = $r['errorName'];
                    $entry['redactedFrame'] = $r['redactedFrame'];
                    $entry['lastSeen'] = $now;
                    // A later occurrence that knows its action names it; one
                    // that does not leaves the last known action alone.
                    if (isset($r['traceId']) && Trace::isValidTraceId($r['traceId'])) {
                        $entry['traceId'] = $r['traceId'];
                        $spanId = SpanScope::sanitizeSpanId($r['spanId'] ?? null);
                        if ($spanId !== null) {
                            $entry['spanId'] = $spanId;
                        }
                    }
                    $pending[$sig] = $entry;
                } else {
                    $pending[$sig] = [
                        'signature' => $sig,
                        'errorName' => $r['errorName'],
                        'kind' => $r['kind'],
                        'redactedFrame' => $r['redactedFrame'],
                        'sessionTotal' => 1,
                        'unsent' => 1,
                        'lastSeen' => $now,
                    ];
                    if (isset($r['traceId']) && Trace::isValidTraceId($r['traceId'])) {
                        $pending[$sig]['traceId'] = $r['traceId'];
                        $spanId = SpanScope::sanitizeSpanId($r['spanId'] ?? null);
                        if ($spanId !== null) {
                            $pending[$sig]['spanId'] = $spanId;
                        }
                    }
                }
                // Bound the map: drop the least recently seen classes first.
                if (count($pending) > self::MAX_CRASHES) {
                    uasort(
                        $pending,
                        static fn ($a, $b) => (int) ($a['lastSeen'] ?? 0) <=> (int) ($b['lastSeen'] ?? 0)
                    );
                    $pending = array_slice($pending, -self::MAX_CRASHES, null, true);
                }

                return $pending;
            }, []);
        });
    }

    /**
     * The wire batch: at most MAX_BATCH classes that have unsent occurrences.
     *
     * @return list<array<string,mixed>>
     */
    public static function buildBatch(): array
    {
        $pending = Store::get(self::KEY, []);
        if (!is_array($pending)) {
            return [];
        }
        $batch = [];
        foreach ($pending as $e) {
            if (!is_array($e)) {
                continue;
            }
            $unsent = (int) ($e['unsent'] ?? 0);
            if ($unsent <= 0) {
                continue;
            }
            $item = [
                'signature' => (string) $e['signature'],
                'errorName' => (string) $e['errorName'],
                'kind' => (string) $e['kind'],
                'redactedFrame' => (string) $e['redactedFrame'],
                'countBucket' => self::bucketCount((int) ($e['sessionTotal'] ?? 1)),
                'occurrences' => min($unsent, self::MAX_OCCURRENCES),
            ];
            // Added only when present and valid: malformed persisted values are
            // dropped rather than making the server refuse the crash batch.
            $traceId = isset($e['traceId']) && is_string($e['traceId']) ? $e['traceId'] : null;
            if (Trace::isValidTraceId($traceId)) {
                $item['traceId'] = $traceId;
                $spanId = SpanScope::sanitizeSpanId($e['spanId'] ?? null);
                if ($spanId !== null) {
                    $item['spanId'] = $spanId;
                }
            }
            $batch[] = $item;
            if (count($batch) >= self::MAX_BATCH) {
                break;
            }
        }

        return $batch;
    }

    /**
     * Send whatever is pending. The issues channel is on for any registered
     * project — this does not wait for full telemetry.
     */
    public static function flushPending(): bool
    {
        return (bool) Safe::run(static function (): bool {
            if (RuntimeFlags::disabled() || !Telemetry::issuesEnabled()) {
                return false;
            }
            $batch = self::buildBatch();
            if ($batch === []) {
                return false;
            }
            $installId = Telemetry::installId();
            $token = Telemetry::deleteToken();
            if ($installId === null || $token === null) {
                return false;
            }
            try {
                $res = Transmit::safeTransmit(
                    RuntimeFlags::apiBase() . '/crashes',
                    [
                        'installId' => $installId,
                        'packageVersion' => Snapshot::pep440ToSemver(Thresholds::RUNTIME_VERSION),
                        'crashes' => $batch,
                    ],
                    $token,
                    5000
                );
            } catch (\Throwable $e) {
                Telemetry::noteUploadRejected('unreachable');
                return false;
            }
            $status = (int) $res['status'];
            if ($status < 200 || $status >= 300) {
                Telemetry::noteUploadRejected(
                    $status === 0 ? 'unreachable'
                        : (($status === 401 || $status === 403) ? 'unauthorized'
                            : ($status >= 500 ? 'server-error' : 'rejected'))
                );
                return false;
            }
            Telemetry::noteUploadAccepted();
            self::applySent($batch);

            return true;
        }, false);
    }

    /** @param list<array<string,mixed>> $batch */
    private static function applySent(array $batch): void
    {
        $sent = [];
        foreach ($batch as $item) {
            $sent[(string) $item['signature']] = (int) $item['occurrences'];
        }
        Store::update(self::KEY, static function ($cur) use ($sent) {
            $pending = is_array($cur) ? $cur : [];
            foreach ($sent as $sig => $occ) {
                if (isset($pending[$sig]) && is_array($pending[$sig])) {
                    $pending[$sig]['unsent'] = max(
                        0,
                        (int) ($pending[$sig]['unsent'] ?? 0) - $occ
                    );
                }
            }

            return $pending;
        }, []);
    }

    /** Bucketed counts — the server never sees an exact crash total. */
    public static function bucketCount(int $n): string
    {
        if ($n <= 1) {
            return '1';
        }
        if ($n <= 5) {
            return '2-5';
        }
        if ($n <= 20) {
            return '6-20';
        }
        if ($n <= 100) {
            return '21-100';
        }

        return '100+';
    }

    public static function crashCount(): int
    {
        $pending = Store::get(self::KEY, []);

        return is_array($pending) ? count($pending) : 0;
    }

    /**
     * Total crash OCCURRENCES since telemetry start — fatal errors plus uncaught
     * exceptions, summed across every distinct crash class (not the class count).
     * Read-only reuse of the counts this reporter's existing set_exception_handler
     * + fatal shutdown scan already accrue; adds no second hook. Guest-safe.
     */
    public static function sessionCrashTotal(): int
    {
        $pending = Store::get(self::KEY, []);
        if (!is_array($pending)) {
            return 0;
        }
        $total = 0;
        foreach ($pending as $e) {
            if (is_array($e)) {
                $total += (int) ($e['sessionTotal'] ?? 0);
            }
        }

        return $total;
    }

    public static function formatRedactedFrame(string $func, string $file, int $line): string
    {
        $func = self::sanitizeFunc($func);
        $base = $file !== '' ? basename($file) : '<unknown>';
        $suffix = $line > 0 ? ':' . $line : '';
        $s = $func . ' (' . $base . $suffix . ')';

        return self::truncate($s, 160);
    }

    public static function sanitizeErrorName(string $name): string
    {
        $out = '';
        $len = strlen($name);
        for ($i = 0; $i < $len && strlen($out) < 80; $i++) {
            $c = $name[$i];
            if (ctype_alnum($c) || $c === '_' || $c === '$') {
                $out .= $c;
            }
        }

        return $out !== '' ? $out : 'Error';
    }

    private static function sanitizeFunc(string $func): string
    {
        $out = preg_replace('/[^A-Za-z0-9_.:$\\\\<>-]/', '', $func);
        $out = is_string($out) ? $out : '';

        return $out !== '' ? self::truncate($out, 100) : '<unknown>';
    }

    private static function shortClassName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    /**
     * The wire enum only knows uncaught / unhandledRejection / render. PHP
     * produces the first of those; anything unexpected is mapped there rather
     * than inventing a value the server would reject.
     */
    private static function normalizeKind(string $kind): string
    {
        return in_array($kind, ['uncaught', 'unhandledRejection', 'render'], true)
            ? $kind
            : 'uncaught';
    }

    private static function levelName(int $level): string
    {
        return match ($level) {
            E_ERROR => 'FatalError',
            E_PARSE => 'ParseError',
            E_CORE_ERROR => 'CoreError',
            E_COMPILE_ERROR => 'CompileError',
            E_USER_ERROR => 'UserError',
            E_RECOVERABLE_ERROR => 'RecoverableError',
            default => 'Error',
        };
    }

    private static function hash(string $s): string
    {
        return substr(hash('sha256', $s), 0, 16);
    }

    private static function truncate(string $s, int $max): string
    {
        return strlen($s) > $max ? substr($s, 0, $max) : $s;
    }

    public static function clear(): void
    {
        Store::delete(self::KEY);
    }

    /** Test seam. */
    public static function activateForTests(): void
    {
        self::$active = true;
    }
}
