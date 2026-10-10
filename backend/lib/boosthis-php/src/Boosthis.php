<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * The whole public surface of the PHP kit.
 *
 * A developer should never need to reach past this class. Everything here is
 * guarded: if any of it fails, the host application carries on exactly as if
 * Boosthis were not installed.
 *
 * The shortest useful integration is one line, as early in the request as
 * possible:
 *
 *     \Boosthis\Boosthis::start();
 *
 * That measures the request, watches for crashes, and sends what it is allowed
 * to send after the response has already reached the visitor.
 */
final class Boosthis
{
    private static bool $workerShutdownArmed = false;

    private function __construct()
    {
    }

    /**
     * Begin measuring this request and register everything that needs to run at
     * the end of it. Safe to call twice.
     *
     * @param array{
     *   route?:string,
     *   installId?:string,
     *   appName?:string,
     *   projectKey?:string,
     *   aiEndpoints?:string|list<string>,
     *   shareMeterWithAI?:bool
     * } $options
     */
    public static function start(array $options = []): void
    {
        AiCalls::configure($options['aiEndpoints'] ?? null);
        AiCalls::arm();
        StartAnnounce::beginStartAnnouncement();
        try {
            $state = 'visible';
            try {
                $disabled = RuntimeFlags::disabled();
            } catch (\Throwable) {
                $disabled = true;
            }
            try {
                $state = $disabled ? 'switched-off' : (
                    Bubble::resolveVisibility(isset($options['bubble']) ? (bool) $options['bubble'] : null)
                        ? 'visible' : 'hidden-by-setting'
                );
            } catch (\Throwable) {
                $state = $disabled ? 'switched-off' : 'visible';
            }
            StartAnnounce::announceKitStart(ProjectKey::resolve($options['projectKey'] ?? null)['key'], $state);
            ExtraMeters::announceUnreadableSources();
        } catch (\Throwable) {
        } finally {
            StartAnnounce::flushHeldRefusals();
        }
        Instrument::auto($options);
        // Laravel queue workers bootstrap the application without opening a
        // port. Attach to their event dispatcher eagerly when it is already
        // available; class names stay strings so non-Laravel apps load nothing.
        Safe::fire(static function (): void {
            if (function_exists('app')) {
                $events = app('events');
                if (is_object($events)) {
                    JobWork::attachLaravelQueue($events);
                }
            }
        });
    }

    /**
     * Close the request out by hand. Only needed when the host wants the
     * measurement to end before PHP's own shutdown — the middleware adapters
     * call this for you.
     */
    public static function finish(?int $statusCode = null): void
    {
        Instrument::end($statusCode);
        Instrument::flush();
    }

    /**
     * Initialise a CLI queue/scheduler process without pretending it serves an
     * HTTP request. The worker registers immediately and its existing snapshot
     * uploader is flushed at process shutdown.
     *
     * @param array{installId?:string,appName?:string,projectKey?:string,aiEndpoints?:string|list<string>,shareMeterWithAI?:bool} $options
     */
    public static function startWorker(array $options = []): void
    {
        AiCalls::configure($options['aiEndpoints'] ?? null);
        AiCalls::arm();
        Safe::fire(static function () use ($options): void {
            Telemetry::enable($options);
            if (!KillSwitch::isActive()) {
                if (KillSwitch::checkDue()) {
                    KillSwitch::check(Integrity::status(), false);
                }
                if (!KillSwitch::isActive()) {
                    return;
                }
            }
            if (function_exists('app')) {
                $events = app('events');
                if (is_object($events)) {
                    JobWork::attachLaravelQueue($events);
                }
            }
            if (!self::$workerShutdownArmed) {
                self::$workerShutdownArmed = true;
                @register_shutdown_function(static function (): void {
                    Safe::fire(static function (): void {
                        if (Telemetry::fullTelemetry() && KillSwitch::isActive()) {
                            Uploader::uploadSnapshot();
                        }
                    });
                });
            }
        });
    }

    /**
     * Measure one piece of work inside a request.
     *
     * IT RETURNS A FUNCTION — call it when the work is done:
     *
     *     $done = \Boosthis\Boosthis::trackPerf('checkout:tax');
     *     ... the work ...
     *     $done();
     *
     * @return callable():void
     */
    public static function trackPerf(string $label): callable
    {
        $startedAt = microtime(true);
        $traceId = Instrument::traceId();
        $spanId = Trace::currentSpanId();
        $parentSpanId = Trace::currentParentSpanId();

        return static function () use ($label, $startedAt, $traceId, $spanId, $parentSpanId): void {
            Safe::fire(static function () use (
                $label,
                $startedAt,
                $traceId,
                $spanId,
                $parentSpanId
            ): void {
                $durationMs = (microtime(true) - $startedAt) * 1000;
                Samples::record($label, $durationMs);
                if ($traceId !== null && Telemetry::fullTelemetry()) {
                    // Where in the trace this leg STARTED — derived from the
                    // elapsed base plus the time already spent in this request,
                    // so two measured legs of one action draw as two bars.
                    SpanEmitter::record(
                        $traceId,
                        $label,
                        $durationMs,
                        (float) Trace::spanStartOffset($durationMs),
                        $spanId,
                        $parentSpanId
                    );
                }
            });
        };
    }

    /**
     * Run one identifiable call while measuring its name and arguments.
     *
     * @param list<mixed> $args
     * @return mixed the body's original return value
     */
    public static function trackCall(string $name, array $args, callable $fn)
    {
        $startedAt = microtime(true);
        try {
            return $fn();
        } finally {
            Safe::fire(static function () use ($name, $args, $startedAt): void {
                RepeatedWork::noteWatchedCall(
                    RepeatedWork::callIdentity($name, $args),
                    (microtime(true) - $startedAt) * 1000
                );
            });
        }
    }

    /**
     * Watch statements prepared by this PDO handle from this point forward.
     * Call immediately after constructing PDO and before preparing statements.
     */
    public static function watchPdo(\PDO $pdo): bool
    {
        return DbWork::watchPdo($pdo);
    }

    /**
     * Tell the kit about one outbound call your app made. Pass a full URL so
     * repeated-work can identify it; bare hosts still feed the older anonymous
     * network/retry meters but are deliberately not treated as call identities.
     * This is the only way
     * the network and retry-storm meters learn anything — like the Go and Java
     * kits, PHP measures outbound traffic through an explicit call, never by
     * monkey-patching the host's HTTP client.
     */
    public static function recordOutbound(
        string $host,
        float $durationMs,
        bool $stalled = false,
        bool $failed = false,
        array $responseHeaders = []
    ): void {
        Safe::fire(static function () use ($host, $durationMs, $stalled, $failed, $responseHeaders): void {
            // Two readers, one observation: the network axis learns the timing,
            // the retry-storm detector learns that this host was called again.
            LiveDetectors::recordOutboundAttempt($host);
            MeterAxes::recordOutbound($durationMs, $stalled);
            DependencyWork::record(
                $host,
                $durationMs,
                $stalled ? 'timedout' : ($failed ? 'failed' : 'ok')
            );
            RequestErrorTimerReadings::noteUpstreamCache($responseHeaders);
            LiveDetectors::recordOutboundResult(
                (int) round(max(0.0, $durationMs)),
                $stalled ? LiveDetectors::OUTBOUND_STALL : LiveDetectors::OUTBOUND_OK
            );
        });
    }

    /**
     * Record a completed native cURL transfer. cURL is the PHP client that
     * exposes connect and TLS phases separately: CURLINFO_NUM_CONNECTS proves
     * whether this transfer opened a connection, and APPCONNECT/CONNECT report
     * setup without response time. The effective URL is classified in memory
     * and discarded.
     *
     * @param mixed $handle a CurlHandle/resource accepted by curl_getinfo()
     */
    public static function recordCurl($handle, ?int $statusCode = null): void
    {
        Safe::fire(static function () use ($handle, $statusCode): void {
            if (!function_exists('curl_getinfo')) {
                return;
            }
            $info = @curl_getinfo($handle);
            if (!is_array($info)) {
                return;
            }
            $url = is_string($info['url'] ?? null) ? $info['url'] : '';
            $totalMs = max(0.0, (float) ($info['total_time'] ?? 0) * 1000);
            $code = $statusCode ?? (int) ($info['http_code'] ?? 0);
            $new = (int) ($info['num_connects'] ?? 0) > 0;
            $tls = (float) ($info['appconnect_time'] ?? 0);
            $connect = (float) ($info['connect_time'] ?? 0);
            $setupMs = $new ? max($connect, $tls) * 1000 : null;
            $method = 'GET';
            if (defined('CURLINFO_EFFECTIVE_METHOD')) {
                $effectiveMethod = @curl_getinfo($handle, CURLINFO_EFFECTIVE_METHOD);
                if (is_string($effectiveMethod) && $effectiveMethod !== '') {
                    $method = $effectiveMethod;
                }
            }
            AiCalls::record($url, $totalMs, $code, $method);
            LiveDetectors::recordOutboundAttempt($url);
            MeterAxes::recordOutbound($totalMs, false);
            LiveDetectors::recordOutboundResult(
                (int) round($totalMs),
                $code === 429 || $code >= 500
                    ? LiveDetectors::OUTBOUND_ERROR
                    : LiveDetectors::OUTBOUND_OK
            );
            DependencyWork::record(
                $url,
                $totalMs,
                $code === 429 || $code >= 500 ? 'failed' : 'ok',
                $setupMs,
                $new
            );
            RequestErrorTimerReadings::noteOutbound($url, $new);
        });
    }

    /**
     * Record only a dependency's cache verdict when an HTTP client exposes
     * response headers separately. Only five fixed cache-status names are read;
     * values are classified and discarded immediately.
     */
    public static function recordUpstreamCache(array $responseHeaders): void
    {
        Safe::fire(static function () use ($responseHeaders): void {
            RequestErrorTimerReadings::noteUpstreamCache($responseHeaders);
        });
    }

    /**
     * Record a completed AI-capable HTTP call made through Guzzle, a stream or
     * another client PHP cannot safely patch. Headers and bodies are never
     * accepted; the URL is classified in memory and discarded.
     */
    public static function recordAiCall(
        string $url,
        float $durationMs,
        int $statusCode,
        string $method = 'POST'
    ): void {
        AiCalls::record($url, $durationMs, $statusCode, $method);
    }

    /**
     * Mark one background job run. The returned closure is idempotent; pass
     * true when the run failed.
     *
     * @param array{queuedAtMs?:int|float,attempt?:int,system?:string,hosted?:bool} $options
     * @return callable(bool):void
     */
    public static function beginJob(string $name, array $options = []): callable
    {
        $token = JobWork::begin($name, $options);
        $closed = false;
        return static function (bool $failed = false) use ($token, &$closed): void {
            if ($closed) {
                return;
            }
            $closed = true;
            JobWork::finish($token, $failed);
        };
    }

    /**
     * Measure a synchronous job callback and rethrow the host's exception.
     *
     * @param array{queuedAtMs?:int|float,attempt?:int,system?:string,hosted?:bool} $options
     * @return mixed
     */
    public static function trackJob(string $name, callable $job, array $options = [])
    {
        $done = self::beginJob($name, $options);
        try {
            $result = $job();
            $done(false);
            return $result;
        } catch (\Throwable $error) {
            $done(true);
            throw $error;
        }
    }

    /** Explicit adapter seam for Laravel queue workers. */
    public static function attachLaravelQueue(object $events): void
    {
        JobWork::attachLaravelQueue($events);
    }

    /**
     * Mark one application-owned socket or stream open. The returned opaque
     * token is the only value needed by the activity and close calls.
     *
     * @param array{flowMeasurable?:bool} $options
     */
    public static function openConnection(string $endpoint, array $options = []): string
    {
        return LiveConnections::open($endpoint, $options);
    }

    /** Mark traffic on a held-open connection; payloads are never accepted. */
    public static function connectionActivity(
        string $connection,
        int $messages = 1,
        int $backlog = 0
    ): void {
        LiveConnections::activity($connection, $messages, $backlog);
    }

    /** Mark an application-initiated clean close. */
    public static function closeConnection(string $connection): void
    {
        LiveConnections::close($connection, true);
    }

    /** Mark a connection that ended without an application close. */
    public static function dropConnection(string $connection): void
    {
        LiveConnections::close($connection, false);
    }

    /**
     * Tell the kit to ignore a host in the outbound meters — useful when your
     * app proxies its own health checks and you do not want them counted.
     */
    public static function ignoreOutboundHost(string $host): void
    {
        LiveDetectors::ignoreHost($host);
    }

    /**
     * Headers to attach to an outbound HTTP call so the dashboard can join this
     * request to whatever serves it. Returns an empty array when there is
     * nothing to propagate.
     *
     * @return array<string,string>
     */
    public static function traceHeaders(): array
    {
        return Trace::outboundHeaders();
    }

    /** Is the kit measuring right now? */
    public static function isEnabled(): bool
    {
        return Telemetry::isEnabled() && KillSwitch::isActive();
    }

    /**
     * The picture the kit would upload right now, for local inspection.
     *
     * @return array<string,mixed>
     */
    public static function snapshot(): array
    {
        return Safe::run(static fn (): array => Snapshot::capturePerfSnapshot(), []) ?? [];
    }

    /**
     * A plain answer to "is this working?" — the same facts the MCP
     * connection_status tool reports, so a developer and their AI see one
     * story. Every field is observed, never assumed.
     *
     * @return array<string,mixed>
     */
    public static function connectionStatus(): array
    {
        return Safe::run(static function (): array {
            $installId = Telemetry::installId();
            $projectKey = Telemetry::projectKeyDetails();

            return [
                'runtime' => Telemetry::RUNTIME,
                'kitVersion' => Thresholds::RUNTIME_VERSION,
                'phpVersion' => PHP_VERSION,
                'sapi' => BuildIdentity::sapi(),
                'registered' => Telemetry::isRegistered(),
                'installIdKnown' => $installId !== null,
                'hasProjectKey' => $projectKey['key'] !== null,
                'projectDisplay' => ProjectIdentity::displayCurrent(),
                'projectKeyDisplay' => $projectKey['display'],
                'projectKeySource' => ProjectKey::describe($projectKey),
                'entitlement' => KillSwitch::visibleStatus(),
                'fullTelemetry' => Telemetry::fullTelemetry(),
                'issuesEnabled' => Telemetry::issuesEnabled(),
                'sampleCount' => Samples::count(),
                'crashClasses' => CrashReporter::crashCount(),
                'storeWritable' => Store::isWritable(),
                'storeBackend' => Store::backend(),
                'disabledByEnv' => RuntimeFlags::disabled(),
            ];
        }, []) ?? [];
    }

    /**
     * Erase this install: hand the delete token back to the server and clear
     * every local file the kit wrote. After this the kit is silent until it is
     * registered again.
     */
    public static function forget(): bool
    {
        SampleUploader::clear();
        $forgot = Telemetry::forget();
        MeterAxes::clearMeters();
        AiCalls::clear();
        DependencyWork::clear();
        DbWork::clear();
        JobWork::clear();
            LiveConnections::clear();

        return $forgot;
    }

    /** Send everything pending right now instead of waiting for the cadence. */
    public static function flushNow(): void
    {
        Safe::fire(static function (): void {
            CrashReporter::flushPending();
            Candidates::reportNow();
            if (Telemetry::fullTelemetry()) {
                Uploader::flushSamples(true);
                Uploader::flushSpans();
                Uploader::uploadSnapshot();
            }
        });
    }
}
