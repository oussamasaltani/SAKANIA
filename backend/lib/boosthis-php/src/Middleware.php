<?php

declare(strict_types=1);

namespace Boosthis;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 middleware, for Slim, Mezzio, Laminas and anything else that speaks
 * the standard.
 *
 *     $app->add(new \Boosthis\Middleware());
 *
 * Add it as the OUTERMOST middleware. Anything mounted above it is work the kit
 * cannot see, and a shedding or rate-limiting valve above it would make its own
 * rejections look like your app's responses.
 *
 * This file is the only part of the kit that touches PSR interfaces, and it is
 * only ever loaded if you reference it. Projects with no PSR-7 stack simply
 * never autoload it and need no extra package.
 */
final class Middleware implements MiddlewareInterface
{
    /** @var array<string,mixed> */
    private array $options;

    /**
     * @param array{
     *     installId?:string,
     *     appName?:string,
     *     projectKey?:string,
     *     responseFactory?:mixed,
     *     app?:mixed
     * } $options
     *
     * Pass a PSR-17 `responseFactory` (a ResponseFactoryInterface, or any
     * callable(int):ResponseInterface) to have this middleware serve the
     * local-only status page at `/_boosthis/status`. Without it the middleware
     * cannot build a response without adding a PSR-17 dependency, so the status
     * page is left to \Boosthis\StatusPage::serve() — see the guide. Apps with
     * no factory keep working exactly as before.
     */
    public function __construct(array $options = [], $app = null)
    {
        $this->options = $options;
        // PSR-15 hands process() only a request and handler. Slim users can
        // hand over the app here (`new Middleware([], $app)`) or use
        // RouteInventory::registerApp($app) when a container builds middleware.
        RouteInventory::registerApp($app ?? ($options['app'] ?? null));
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        // The kit's own two addresses: the in-app page a developer opens to see
        // this kit is working, and the panel read that page fetches its numbers
        // from. Answered BEFORE any instrumentation, so the kit never measures
        // or traces itself.
        //
        // The kit depends on no PSR-7 package, so it can only build a response
        // when this app ships an implementation it recognises. If it cannot,
        // it falls through untouched: the developer loses the page, never the
        // request.
        $own = null;
        try {
            $own = Page::routeFor($request->getUri()->getPath(), $request->getMethod());
        } catch (\Throwable $e) {
            $own = null;
        }
        if (is_array($own)) {
            // Belt and braces: Page::psr7() guards itself, and the call site
            // guards it again. A route this kit serves must not be able to
            // raise anything into the host's error handling, however the code
            // inside it changes later.
            $ownResponse = Safe::run(static fn (): ?object => Page::psr7($own), null);
            if ($ownResponse instanceof ResponseInterface) {
                return $ownResponse;
            }
        }

        // The status page sits one path below those, and answers the earlier
        // question: is this kit registered, is sharing on, when was the last
        // upload accepted — and if nothing is being sent, why. The paths are
        // exact matches, so neither handler swallows the other, and unlike the
        // badge page above this one is never gated on the badge being enabled:
        // an API with no UI is exactly who needs it. Also answered BEFORE any
        // instrumentation — a probe of this internal path is not a route of the
        // host app and must not be measured as one. Only possible when a PSR-17
        // factory was supplied; otherwise fall through and let the app (or
        // StatusPage::serve()) run.
        $statusResponse = $this->maybeServeStatus($request);
        if ($statusResponse !== null) {
            return $statusResponse;
        }

        $options = $this->options;
        Safe::fire(static function () use ($request, $options): void {
            $route = self::routeLabel($request);
            Instrument::begin($route === null ? $options : ['route' => $route] + $options);
        });

        try {
            $response = $handler->handle($request);
        } catch (\Throwable $e) {
            // Watch, never intervene: record it and hand the exception straight
            // back to the host's own error handling.
            Safe::fire(static function () use ($e): void {
                RequestErrorTimerReadings::noteUnhandled();
                CrashReporter::capture($e, 'uncaught');
                Instrument::end(500);
            });

            throw $e;
        }

        Safe::fire(static function () use ($request, $response): void {
            Instrument::end(
                $response->getStatusCode(),
                $request->hasHeader('Authorization') && $request->getHeaderLine('Authorization') !== '',
                $response->hasHeader('WWW-Authenticate') && $response->getHeaderLine('WWW-Authenticate') !== '',
                true
            );
        });

        return $response;
    }

    /**
     * Build a response for the local-only status page when this request targets
     * it, or null to let the request continue.
     *
     * Enforces the SAME strict loopback rule as StatusPage::serve(): the real
     * TCP peer (REMOTE_ADDR from the request's server params) is loopback AND no
     * proxy/forwarding header is present. A non-loopback request to the status
     * path is answered with the 403 body — it is HANDLED here, never allowed to
     * fall through to the host's routing.
     *
     * Only runs when a PSR-17 `responseFactory` was supplied; without one there
     * is no dependency-free way to build a ResponseInterface, so the method
     * returns null and the front-controller hook is the seam. Fail-safe: any
     * error returns null so a bug here can never break the host request.
     */
    private function maybeServeStatus(ServerRequestInterface $request): ?ResponseInterface
    {
        $options = $this->options;

        return Safe::run(static function () use ($request, $options): ?ResponseInterface {
            $server = $request->getServerParams();
            if (!is_array($server)) {
                $server = [];
            }
            // PSR-7 exposes the method and path on the request, not just in the
            // server params — prefer those so a synthetic ServerRequest works.
            $server['REQUEST_METHOD'] = $request->getMethod();
            $server['REQUEST_URI'] = $request->getUri()->getPath();

            // PSR-7 also keeps HEADERS in their own bag, and a server may fill
            // that bag without mirroring anything into the server params. Fold
            // the names in, or a proxied request whose only trace of the hop is
            // X-Forwarded-For in the header bag would read as a direct local
            // connection and be served the page.
            if (method_exists($request, 'getHeaders')) {
                $bag = $request->getHeaders();
                if (is_array($bag)) {
                    $server = StatusPage::withHeaderNames($server, array_keys($bag));
                }
            }

            if (!StatusPage::isStatusRequest($server)) {
                return null;
            }

            $factory = $options['responseFactory'] ?? null;
            $make = self::responseMaker($factory);
            if ($make === null) {
                // No factory: cannot serve here without a PSR-17 dependency.
                return null;
            }

            if (StatusPage::isDirectLoopback($server)) {
                return self::writeResponse($make(200), StatusPage::html());
            }

            return self::writeResponse($make(403), StatusPage::forbiddenHtml());
        }, null);
    }

    /**
     * Normalise a supplied factory into `callable(int):ResponseInterface`, or
     * null when it is not something we can call. Accepts a PSR-17
     * ResponseFactoryInterface (via createResponse) or any callable.
     *
     * @param mixed $factory
     * @return null|callable(int):ResponseInterface
     */
    private static function responseMaker($factory): ?callable
    {
        if ($factory === null) {
            return null;
        }
        if (is_object($factory) && method_exists($factory, 'createResponse')) {
            return static fn (int $code): ResponseInterface => $factory->createResponse($code);
        }
        if (is_callable($factory)) {
            return static function (int $code) use ($factory): ResponseInterface {
                $res = $factory($code);
                if (!$res instanceof ResponseInterface) {
                    throw new \RuntimeException('responseFactory did not return a PSR-7 response');
                }

                return $res;
            };
        }

        return null;
    }

    /** Attach the two fixed headers and the body to a PSR-7 response. */
    private static function writeResponse(ResponseInterface $response, string $body): ResponseInterface
    {
        $response = $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
        $response->getBody()->write($body);

        return $response;
    }

    /**
     * A route LABEL from the routing layer when the framework put one there,
     * otherwise null so Instrument falls back to its own templating.
     */
    private static function routeLabel(ServerRequestInterface $request): ?string
    {
        return Safe::run(static function () use ($request): ?string {
            $method = $request->getMethod();
            // Slim and Mezzio both hand the matched route down as an attribute;
            // the pattern is the label we want, never the concrete URL.
            foreach (['__route__', 'route', 'routeName'] as $attr) {
                $route = $request->getAttribute($attr);
                if (is_object($route) && method_exists($route, 'getPattern')) {
                    $pattern = $route->getPattern();
                    if (is_string($pattern) && $pattern !== '') {
                        return $method . ' ' . $pattern;
                    }
                }
                if (is_string($route) && $route !== '') {
                    return $method . ' ' . $route;
                }
            }

            return null;
        });
    }
}
