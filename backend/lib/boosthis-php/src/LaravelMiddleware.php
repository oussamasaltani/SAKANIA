<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * Laravel / Lumen middleware.
 *
 * Register it as the first entry in the `web` and `api` groups, or globally in
 * bootstrap/app.php:
 *
 *     ->withMiddleware(function (Middleware $middleware) {
 *         $middleware->prepend(\Boosthis\LaravelMiddleware::class);
 *     })
 *
 * Deliberately untyped: Laravel resolves middleware by convention, not by
 * interface, so this file pulls in nothing from the framework and the kit stays
 * dependency-free.
 */
final class LaravelMiddleware
{
    /** @param mixed $app */
    public function __construct($app = null)
    {
        // Laravel's container normally constructs middleware. Its global app()
        // accessor is therefore the dependency-free fallback; an explicit app
        // remains useful to tests and non-standard containers.
        RouteInventory::registerApp($app);
        if ($app === null) {
            Safe::fire(static function (): void {
                if (function_exists('app')) {
                    RouteInventory::registerApp(app());
                }
            });
        }
    }

    /**
     * @param mixed $request
     * @param callable $next
     * @return mixed
     */
    public function handle($request, $next)
    {
        // The kit's own two addresses: the in-app page a developer opens to see
        // this kit is working, and the panel read that page fetches its numbers
        // from. Answered BEFORE any instrumentation, so the kit never measures
        // or traces itself. Still untyped — the response class is resolved at
        // runtime, so this file pulls in nothing from the framework.
        $own = null;
        try {
            $path = is_object($request) && method_exists($request, 'getPathInfo')
                ? $request->getPathInfo()
                : null;
            $method = is_object($request) && method_exists($request, 'getMethod')
                ? $request->getMethod()
                : 'GET';
            $own = Page::routeFor(
                is_string($path) ? $path : null,
                is_string($method) ? $method : 'GET'
            );
        } catch (\Throwable $e) {
            $own = null;
        }
        if (is_array($own)) {
            $made = Safe::run(static function () use ($own) {
                $class = '\\Illuminate\\Http\\Response';
                if (!class_exists($class)) {
                    return null;
                }

                return new $class($own['body'], $own['status'], $own['headers']);
            }, null);
            if (is_object($made)) {
                return $made;
            }
        }

        // The status page at /_boosthis/status — one path below those, and the
        // earlier question they do not answer: is this kit registered, is
        // sharing on, when was the last upload accepted, and if nothing is
        // being sent, why. Exact-match paths, so neither handler swallows the
        // other, and unlike the badge page above this one is never gated on the
        // badge being enabled: an API with no UI is exactly who needs it.
        // Laravel always has Illuminate\Http\Response available at runtime, so
        // this needs no extra dependency and no manual wiring — a Laravel app
        // that prepends this middleware gets the page for free. A probe of this
        // internal path is not a host route and is not measured.
        $statusResponse = self::maybeServeStatus($request);
        if ($statusResponse !== null) {
            return $statusResponse;
        }

        Safe::fire(static function () use ($request): void {
            Instrument::begin(self::routeOptions($request));
        });

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            Safe::fire(static function () use ($e): void {
                RequestErrorTimerReadings::noteUnhandled();
                CrashReporter::capture($e, 'uncaught');
                Instrument::end(500);
            });

            throw $e;
        }

        Safe::fire(static function () use ($request, $response): void {
            $status = null;
            if (is_object($response) && method_exists($response, 'getStatusCode')) {
                $code = $response->getStatusCode();
                $status = is_int($code) ? $code : null;
            }
            $authorization = method_exists($request, 'hasHeader') && $request->hasHeader('Authorization')
                && (string) $request->header('Authorization') !== '';
            $challenge = is_object($response) && method_exists($response, 'headers')
                && $response->headers->has('WWW-Authenticate')
                && (string) $response->headers->get('WWW-Authenticate') !== '';
            $matched = method_exists($request, 'route') ? $request->route() !== null : null;
            Instrument::end($status, $authorization, $challenge, true, $matched);
        });

        return $response;
    }

    /**
     * Laravel's terminable hook. Anything here runs after the response has been
     * sent to the visitor, which is exactly where the kit's network work
     * belongs.
     *
     * @param mixed $request
     * @param mixed $response
     */
    public function terminate($request, $response): void
    {
        // Guarded here as well as inside flush(): the host calls this hook, so
        // anything raised out of it is the host's problem, not ours.
        Safe::fire(static function (): void {
            Instrument::flush();
        });
    }

    /**
     * Build a Laravel response for the local-only status page when this request
     * targets it, or null to let the request continue.
     *
     * Enforces the SAME strict loopback rule as StatusPage::serve(): the real
     * TCP peer (REMOTE_ADDR) is loopback AND no proxy/forwarding header is
     * present — Laravel's trusted-proxy handling is deliberately bypassed, since
     * trusting X-Forwarded-For would make this page reachable from the public
     * internet. A non-loopback request to the status path gets the 403 body and
     * is HANDLED here, never allowed to fall through.
     *
     * Fail-safe: any error returns null so a bug here can never break the host
     * request.
     *
     * @param mixed $request
     * @return mixed|null the Laravel response, or null to continue
     */
    private static function maybeServeStatus($request)
    {
        return Safe::run(static function () use ($request) {
            // Read the raw request superglobals, not Laravel's proxy-aware
            // helpers: the strict guard must see the real TCP peer.
            $server = self::serverParams($request);
            if (!StatusPage::isStatusRequest($server)) {
                return null;
            }
            if (!class_exists(\Illuminate\Http\Response::class)) {
                // No framework response class at runtime: leave it to the
                // documented front-controller hook.
                return null;
            }

            [$status, $body] = StatusPage::isDirectLoopback($server)
                ? [200, StatusPage::html()]
                : [403, StatusPage::forbiddenHtml()];

            return new \Illuminate\Http\Response($body, $status, [
                'Content-Type' => 'text/html; charset=utf-8',
                'Cache-Control' => 'no-store',
            ]);
        }, null);
    }

    /**
     * The CGI server params for the strict guard. Prefer the raw superglobal so
     * REMOTE_ADDR is the real TCP peer; fall back to the request object's
     * server bag when the superglobal is empty (e.g. under a test harness).
     *
     * @param mixed $request
     * @return array<string,mixed>
     */
    private static function serverParams($request): array
    {
        $server = is_array($_SERVER ?? null) ? $_SERVER : [];
        if ($server === [] && is_object($request) && isset($request->server)
            && is_object($request->server) && method_exists($request->server, 'all')) {
            $all = $request->server->all();
            $server = is_array($all) ? $all : [];
        }

        // Symfony keeps headers in a HeaderBag that code can set without
        // touching the server bag, so a forwarding header can exist on the
        // request while REMOTE_ADDR is the only thing the server params show.
        // Fold those names in: presence of a hop must never be invisible here.
        if (is_object($request) && isset($request->headers) && is_object($request->headers)
            && method_exists($request->headers, 'all')) {
            $names = $request->headers->all();
            if (is_array($names)) {
                $server = StatusPage::withHeaderNames($server, array_keys($names));
            }
        }

        return $server;
    }

    /**
     * Prefer Laravel's own route pattern ("GET orders/{order}") over the raw
     * URL: it is already a label, and it never carries a customer's id.
     *
     * @param mixed $request
     * @return array<string,mixed>
     */
    private static function routeOptions($request): array
    {
        return Safe::run(static function () use ($request): array {
            if (!is_object($request) || !method_exists($request, 'route')) {
                return [];
            }
            $route = $request->route();
            if (!is_object($route) || !method_exists($route, 'uri')) {
                return [];
            }
            $uri = $route->uri();
            if (!is_string($uri) || $uri === '') {
                return [];
            }
            $method = method_exists($request, 'method') ? $request->method() : 'GET';

            return ['route' => (is_string($method) ? $method : 'GET') . ' /' . ltrim($uri, '/')];
        }, []) ?? [];
    }
}
