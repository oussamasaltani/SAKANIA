<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * The request lifecycle — the one place that knows a PHP request has a
 * beginning and an end.
 *
 * Every other runtime has a process to hang measurement off. PHP does not, so
 * this class does three things and nothing else:
 *
 *   begin()  — as early in the request as the host will let us run
 *   end()    — after the host has finished its work
 *   flush()  — the outbound work, run AFTER the response has left
 *
 * The flush order is the whole point. Under PHP-FPM we call
 * fastcgi_finish_request() first: the visitor already has the page, the
 * connection is closed, and only then does the kit talk to the network. A
 * visitor can never wait on Boosthis. On SAPIs without that function we still
 * run in the shutdown phase, and every send has a short bounded timeout.
 */
final class Instrument
{
    private static bool $started = false;
    private static bool $shutdownRegistered = false;
    private static ?float $startedAt = null;
    private static ?string $route = null;
    private static ?string $traceId = null;
    private static int $inFlightAtStart = 0;
    private static ?int $repeatedWorkToken = null;
    private static bool $responseHandedBack = false;

    private function __construct()
    {
    }

    /**
     * Start measuring this request. Safe to call more than once — the second
     * call is a no-op, so a host that wires both a middleware and an
     * auto-prepend file does not double-count.
     *
     * @param array{route?:string,installId?:string,appName?:string,projectKey?:string} $options
     */
    public static function begin(array $options = []): void
    {
        if (self::$started || RuntimeFlags::disabled()) {
            return;
        }
        self::$started = true;
        Safe::fire(static function () use ($options): void {
            self::$startedAt = microtime(true);
            RequestErrorTimerReadings::beginRequest();
            self::$repeatedWorkToken = RepeatedWork::beginRequestWork();
            DependencyWork::beginRequest();
            DbWork::beginRequest();
            self::$route = isset($options['route'])
                ? RouteInventory::normalizePartName((string) $options['route'], true)
                : self::detectRoute();
            self::$traceId = Trace::beginRequest($_SERVER);

            Telemetry::enable($options);
            // Gate 1 first: an install that was never activated does no work
            // beyond this point.
            if (!KillSwitch::isActive() && !KillSwitch::checkDue()) {
                return;
            }
            self::$inFlightAtStart = MeterAxes::noteRequestStart();
            ExtraMeters::beginRequest();
            SuspendSensor::noteRequestBoundary();
            CrashReporter::install();
            SwallowedErrors::install();
            self::registerShutdown();
        });
    }

    /**
     * Close the request out. Called explicitly by the middleware adapters, and
     * from the shutdown handler for hosts that never call it.
     */
    public static function end(
        ?int $statusCode = null,
        bool $authorizationPresent = false,
        bool $challengePresent = false,
        bool $responseHeadersMeasurable = false,
        ?bool $routeMatched = null
    ): void
    {
        if (!self::$started) {
            return;
        }
        if (RuntimeFlags::disabled()) {
            $startedAt = self::$startedAt;
            $durationMs = $startedAt === null ? 0.0 : (microtime(true) - $startedAt) * 1000;
            RepeatedWork::endRequestWork(self::$repeatedWorkToken, $durationMs);
            DbWork::endRequest($durationMs);
            self::$repeatedWorkToken = null;

            return;
        }
        Safe::fire(static function () use (
            $statusCode,
            $authorizationPresent,
            $challengePresent,
            $responseHeadersMeasurable
            , $routeMatched
        ): void {
            $startedAt = self::$startedAt;
            if ($startedAt === null) {
                return;
            }
            $durationMs = (microtime(true) - $startedAt) * 1000;
            $status = $statusCode ?? self::currentStatus();
            $dependencyCounts = DependencyWork::currentRequestCounts();
            if (self::$route !== null) {
                RequestErrorTimerReadings::endRequest(
                    $status,
                    self::$route,
                    (int) $dependencyCounts['count'],
                    (int) $dependencyCounts['failed']
                );
            }
            RepeatedWork::endRequestWork(self::$repeatedWorkToken, $durationMs);
            DependencyWork::endRequest($durationMs);
            // Bank database folds before the response is handed back. PHP has
            // no reliable post-response phase in which request scope survives.
            DbWork::endRequest($durationMs);
            self::$repeatedWorkToken = null;
            $route = self::$route;
            $heldOpen = HeldOpen::isHeldOpenResponse($status);

            MeterAxes::noteRequestEnd();
            AccessPressure::recordResponse($status, null, $routeMatched);
            RefusalHonesty::recordResponse(
                $status,
                $authorizationPresent,
                $challengePresent,
                $responseHeadersMeasurable
            );
            CookieExposure::observeResponse();

            // Gate 1 again — an install can be revoked mid-request.
            if (!KillSwitch::isActive()) {
                if (Uploader::canRetainSamplesWhileLocked()) {
                    // A launch check may still be in flight when a short request
                    // ends. Preserve its visible measurement for the exit knock.
                    if ($heldOpen) {
                        HeldOpen::noteExcluded($durationMs);
                    } elseif ($route !== null) {
                        Samples::record($route, $durationMs);
                    }
                }
                return;
            }

            if ($heldOpen) {
                HeldOpen::noteExcluded($durationMs);
            } elseif ($route !== null) {
                Samples::record($route, $durationMs);
                MeterAxes::recordServed($durationMs, null);
                MeterAxes::recordDeflection(self::$inFlightAtStart, $durationMs);
            }
            RuntimeVitals::sample();
            ExtraMeters::sample(!$heldOpen);
            HostResources::sample();
            CpuScheduling::sample();

            // Gate 2, screen-bearing side: spans only move with full telemetry.
            if (Telemetry::fullTelemetry() && self::$traceId !== null && $route !== null) {
                // Offset from the trace root = the elapsed base adopted on
                // receive (this hop began when the caller sent, per the chained
                // deltas). A constant here would draw every PHP layer at the
                // left edge and let it outrank the true root.
                SpanEmitter::record(
                    self::$traceId,
                    $route,
                    $durationMs,
                    (float) Trace::elapsedBase(),
                    Trace::currentSpanId(),
                    Trace::currentParentSpanId(),
                    'handler',
                    SpanWork::outcomeForStatus($status)
                );
            }
        });
    }

    /**
     * The outbound phase. Runs after the response is on its way to the visitor.
     */
    public static function flush(bool $exiting = false): void
    {
        if (RuntimeFlags::disabled()) {
            return;
        }
        Safe::fire(static function () use ($exiting): void {
            // A fatal that ended the request is still worth counting.
            SwallowedErrors::onShutdown();

            // The bubble is markup, so it has to go out with the page
            // — before the response is closed, never after.
            Bubble::inject();

            // Hand the page back BEFORE any network work.
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
                self::$responseHandedBack = true;
            } elseif (function_exists('litespeed_finish_request')) {
                @litespeed_finish_request();
                self::$responseHandedBack = true;
            }

            // Privacy-safe recurring problems and fix outcomes are always on
            // for a registered install, independently of snapshot sharing.
            Candidates::flushIfDue();

            if ($exiting) {
                // The blocking sample drain owns the bounded activation knock,
                // so permission is settled before it removes its first batch.
                Uploader::flushSamplesBlocking();
            } elseif (KillSwitch::checkDue()) {
                KillSwitch::check(Integrity::status(), false);
            }
            if (!KillSwitch::isActive()) {
                return;
            }

            // Issues channel — on for any registered project.
            CrashReporter::flushPending();

            // Screen-bearing channels — full telemetry only.
            if (Telemetry::fullTelemetry()) {
                if (!$exiting) {
                    Uploader::flushSamples();
                }
                Uploader::flushSpans();
                Uploader::flushSnapshotIfDue();
            } else {
                // Consent turning off also erases anything a prior request
                // buffered while it was on.
                SampleUploader::clear();
            }

            Community::refreshCommunityCacheIfStale();
        });
    }

    /**
     * True once the visitor's response has really left — FPM's
     * fastcgi_finish_request(), or LiteSpeed's equivalent, returned during this
     * request's flush.
     *
     * Anything that wants to WAIT on the network has to ask this first. On a
     * SAPI without either function (mod_php, the built-in server, a CLI
     * script) the shutdown phase still holds the connection open, so a
     * five-second read there is five seconds of a customer's page. The
     * reporting path answers that by not waiting at all — see
     * Transmit::postNoWait().
     */
    public static function responseHandedBack(): bool
    {
        return self::$responseHandedBack;
    }

    /**
     * Test seam: stand in for a SAPI that does (or does not) detach. The CLI
     * test runner never detaches, so a test that wants the FPM path has to say
     * so out loud.
     */
    public static function setResponseHandedBackForTests(bool $value): void
    {
        self::$responseHandedBack = $value;
    }

    /**
     * One-liner for hosts with no middleware layer (WordPress, plain PHP): call
     * this once, as early as possible, and the kit handles the rest.
     *
     * @param array{route?:string,installId?:string,appName?:string,projectKey?:string} $options
     */
    public static function auto(array $options = []): void
    {
        self::begin($options);
        self::registerShutdown();
    }

    private static function registerShutdown(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }
        self::$shutdownRegistered = true;
        @register_shutdown_function(static function (): void {
            Safe::fire(static function (): void {
                self::end(null);
                self::flush(true);
            });
        });
    }

    /** Observed state for tests and proofs; registration is process-idempotent. */
    public static function shutdownArmed(): bool
    {
        return self::$shutdownRegistered;
    }

    public static function traceId(): ?string
    {
        return self::$traceId;
    }

    public static function route(): ?string
    {
        return self::$route;
    }

    /**
     * A route LABEL, never a URL. Query strings and path segments that look
     * like identifiers are collapsed, because a label that carries a customer
     * number is a label we must not transmit.
     */
    public static function detectRoute(): ?string
    {
        return Safe::run(static function (): ?string {
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $uri = $_SERVER['REQUEST_URI'] ?? '/';
            $path = parse_url((string) $uri, PHP_URL_PATH);
            $path = is_string($path) && $path !== '' ? $path : '/';

            return RouteInventory::normalizePartName($method . ' ' . self::templatize($path));
        }, null);
    }

    /**
     * Collapse the parts of a path that vary per visitor: numeric ids, UUIDs,
     * hashes and long opaque tokens all become a placeholder.
     */
    public static function templatize(string $path): string
    {
        $segments = explode('/', $path);
        $out = [];
        foreach ($segments as $seg) {
            if ($seg === '') {
                $out[] = $seg;
                continue;
            }
            if (preg_match('/^\d+$/', $seg) === 1) {
                $out[] = ':id';
                continue;
            }
            if (preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                $seg
            ) === 1) {
                $out[] = ':uuid';
                continue;
            }
            if (preg_match('/^[0-9a-f]{16,}$/i', $seg) === 1) {
                $out[] = ':hash';
                continue;
            }
            if (strlen($seg) > 40) {
                $out[] = ':opaque';
                continue;
            }
            $out[] = $seg;
        }
        $joined = implode('/', $out);

        return $joined === '' ? '/' : $joined;
    }

    private static function currentStatus(): int
    {
        $code = Safe::run(static fn (): int => http_response_code() ?: 200, 200);

        return is_int($code) && $code > 0 ? $code : 200;
    }

    /** Test seam. */
    public static function resetForTests(): void
    {
        self::$started = false;
        self::$shutdownRegistered = false;
        self::$startedAt = null;
        self::$route = null;
        self::$traceId = null;
        self::$inFlightAtStart = 0;
        self::$repeatedWorkToken = null;
        self::$responseHandedBack = false;
        HeldOpen::setResponseHeadersForTests(null);
        RepeatedWork::clear();
        DependencyWork::clear();
        DbWork::clear();
        JobWork::clear();
        LiveConnections::clear();
        RequestErrorTimerReadings::clear();
    }
}
