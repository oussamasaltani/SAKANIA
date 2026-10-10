<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * The in-app page — the one address a back-end developer can open to see this
 * kit is working.
 *
 * The floating badge only ever appears inside HTML the host app already serves.
 * A PHP service is very often an API that returns nothing but JSON, so the
 * badge has nothing to attach to and the developer sees nothing: a healthy
 * install and a dead one look identical from inside their own project.
 *
 * This kit is the odd one out among the back-end kits — until now it served no
 * addresses at all, it only watched requests going past and injected the badge
 * into HTML on the way out. So the page needs a companion: the panel read the
 * page fetches its numbers from. Both are routed here, and both are handled by
 * the same two entry points ({@see Middleware} and {@see LaravelMiddleware}).
 *
 * Deliberate properties:
 *  - No sign-in. It reads only this process's own numbers, so there is no
 *    account to authenticate against.
 *  - No secrets. The markup is static and holds no measurements; the browser
 *    fetches them from the panel read beside it. The account credential the
 *    other kits expose on a loopback-only address is not served here at all.
 *  - It rides the SAME visibility switch as the badge. Serving the page while
 *    hiding the badge — or the reverse — would protect nothing, since both draw
 *    exactly the same closed picture.
 *
 * Framework-agnostic on purpose: this class decides WHAT to answer and returns
 * a plain array. Turning that into a response object is the caller's job,
 * because the kit refuses to depend on any particular PSR-7 implementation.
 */
final class Page
{
    /** The in-app page's address. */
    public const PAGE_PATH = '/_boosthis';

    /** The same page with a trailing slash, so neither habit 404s. */
    public const PAGE_PATH_SLASH = '/_boosthis/';

    /** The closed read the page fetches its numbers from. */
    public const PANEL_PATH = '/_boosthis/panel';

    /** Memoised markup — read from disk once per process. */
    private static ?string $html = null;

    /**
     * Shown only if the packed page file is missing, which would mean a broken
     * install. It still tells the developer the truth: the kit is running, and
     * where to look.
     */
    private const FALLBACK_HTML = '<!doctype html><html><head><meta charset="utf-8">'
        . '<title>Boosthis</title></head><body style="background:#0b0c10;color:#e6e7eb;'
        . 'font-family:system-ui,sans-serif;padding:40px">'
        . '<h1>Boosthis is running</h1>'
        . '<p>This install is measuring, but the page file is missing from the '
        . 'package. Reinstall the kit to restore it.</p>'
        . '<p><a style="color:#f97316" href="https://www.boosthis.com">boosthis.com</a></p>'
        . '</body></html>';

    /** The page markup. Never throws; falls back to a minimal honest page. */
    public static function html(): string
    {
        if (self::$html !== null) {
            return self::$html;
        }

        $html = null;
        try {
            $file = __DIR__ . '/panel_page.html';
            if (is_file($file)) {
                $read = file_get_contents($file);
                if (is_string($read) && $read !== '') {
                    $html = $read;
                }
            }
        } catch (\Throwable $e) {
            $html = null;
        }

        self::$html = $html ?? self::FALLBACK_HTML;

        return self::$html;
    }

    /** True when this path is one the kit answers itself. */
    public static function handles(?string $path): bool
    {
        return $path === self::PAGE_PATH
            || $path === self::PAGE_PATH_SLASH
            || $path === self::PANEL_PATH;
    }

    /**
     * What to answer for this request, or null to leave it to the host.
     *
     * Never throws: a failure here must let the host's own routing run, not
     * take the request down.
     *
     * @return array{status:int,headers:array<string,string>,body:string}|null
     */
    public static function routeFor(?string $path, ?string $method = 'GET'): ?array
    {
        try {
            if ($path === null || !self::handles($path)) {
                return null;
            }
            if ($method !== null && $method !== '' && strtoupper($method) !== 'GET') {
                return null;
            }
            // Same switch as the badge: the page shows what the badge shows.
            if (!Bubble::bubbleEnabled()) {
                return null;
            }

            if ($path === self::PANEL_PATH) {
                return [
                    'status' => 200,
                    'headers' => [
                        'Content-Type' => 'application/json; charset=utf-8',
                        'Cache-Control' => 'no-store',
                    ],
                    'body' => Bubble::panelJson(),
                ];
            }

            return [
                'status' => 200,
                'headers' => [
                    'Content-Type' => 'text/html; charset=utf-8',
                    'Cache-Control' => 'no-store',
                ],
                'body' => self::html(),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Build a PSR-7 response for an answer from {@see routeFor}, or null when
     * this app ships no PSR-7 implementation this kit can drive.
     *
     * The kit depends on no PSR-7 package, so there is nothing it can simply
     * call. Two routes are tried, in order:
     *
     *  1. A PSR-17 RESPONSE FACTORY. Every PSR-15 app ships one, and the
     *     interface is byte-identical across implementations, so this path
     *     needs no per-package knowledge at all.
     *  2. A direct constructor, for the two implementations whose constructor
     *     really is (status, headers, body). Only reached by an app that ships
     *     a PSR-7 package without its factory.
     *
     * Constructors used to be the ONLY route, and that was a silent hole: Slim
     * — the most common PSR-15 setup there is — takes a headers OBJECT and
     * never an array, and Laminas takes (body, status, headers) in the other
     * order. Both threw, both were swallowed, and the developer's page simply
     * 404ed with nothing to explain why. Anything still unrecognised falls
     * through and the host keeps the request: the developer loses the page,
     * never the request.
     *
     * SELF-GUARDED, and not only by the per-attempt catches below. Every
     * `class_exists()` here runs the HOST's autoloader, which is code this kit
     * does not own and cannot vouch for — a host whose autoloader throws (a
     * broken class map, a package half-installed) would otherwise turn OUR page
     * into THEIR error page, in front of their visitor. Nothing escapes this
     * method: the worst case is null, which means the host keeps the request.
     *
     * @param array{status:int,headers:array<string,string>,body:string} $answer
     */
    public static function psr7(array $answer): ?object
    {
        return Safe::run(static function () use ($answer): ?object {
            foreach (
                [
                    'Nyholm\\Psr7\\Factory\\Psr17Factory',
                    'Slim\\Psr7\\Factory\\ResponseFactory',
                    'GuzzleHttp\\Psr7\\HttpFactory',
                    'Laminas\\Diactoros\\ResponseFactory',
                    'Http\\Factory\\Guzzle\\ResponseFactory',
                ] as $class
            ) {
                if (!class_exists($class)) {
                    continue;
                }
                try {
                    /** @psalm-suppress MixedMethodCall */
                    $factory = new $class();
                    if (!$factory instanceof \Psr\Http\Message\ResponseFactoryInterface) {
                        continue;
                    }
                    $response = self::fill($factory->createResponse($answer['status']), $answer);
                    if ($response !== null) {
                        return $response;
                    }
                } catch (\Throwable $e) {
                    // try the next implementation
                }
            }

            // A host that ships php-http/discovery can name its own factory even
            // when it is none of the above.
            try {
                if (class_exists('Http\\Discovery\\Psr17FactoryDiscovery')) {
                    /** @psalm-suppress UndefinedClass */
                    $found = \Http\Discovery\Psr17FactoryDiscovery::findResponseFactory();
                    $response = self::fill($found->createResponse($answer['status']), $answer);
                    if ($response !== null) {
                        return $response;
                    }
                }
            } catch (\Throwable $e) {
                // fall through to the constructors
            }

            foreach (
                [
                    'Nyholm\\Psr7\\Response',
                    'GuzzleHttp\\Psr7\\Response',
                ] as $class
            ) {
                if (!class_exists($class)) {
                    continue;
                }
                try {
                    /** @psalm-suppress MixedMethodCall */
                    $response = new $class(
                        $answer['status'],
                        $answer['headers'],
                        $answer['body']
                    );
                    if ($response instanceof \Psr\Http\Message\ResponseInterface) {
                        return $response;
                    }
                } catch (\Throwable $e) {
                    // try the next implementation
                }
            }

            return null;
        }, null);
    }

    /**
     * Put the headers and body of an answer onto a freshly created response.
     *
     * @param array{status:int,headers:array<string,string>,body:string} $answer
     */
    private static function fill(object $response, array $answer): ?object
    {
        if (!$response instanceof \Psr\Http\Message\ResponseInterface) {
            return null;
        }
        foreach ($answer['headers'] as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        $body = $response->getBody();
        $body->write($answer['body']);
        // The stream is left sitting at the end of what was just written. A
        // host that reads it without rewinding first would send an empty page,
        // which is exactly the failure this class exists to prevent.
        if ($body->isSeekable()) {
            $body->rewind();
        }

        return $response;
    }
}
