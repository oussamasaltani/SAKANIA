<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * Ask the running framework for its whole route table.
 *
 * This asks a public runtime accessor; it does not crawl the app or read source
 * files. An unfamiliar route-object shape loses the whole framework read rather
 * than returning a complete-looking partial map. Every public entry point is
 * guarded because an inventory must never become a host-application failure.
 */
final class RouteInventory
{
    // BOOSTHIS_PART_NAME_V1 — one bound and one refusal rule for inventory and timings.
    public const MAX_PART_NAME = 100;

    /** The closed framework vocabulary accepted by the server. */
    public const ROUTE_SOURCE_WORDS = ['slim', 'laravel'];

    /** The wire cap; total still reports the size before this slice. */
    public const MAX_ROUTE_LIST_ENTRIES = 200;

    private const HTTP_METHODS = [
        'GET', 'POST', 'PUT', 'DELETE', 'PATCH',
        'HEAD', 'OPTIONS', 'TRACE', 'CONNECT',
    ];

    /** @var list<string> */
    private static array $declaredRoutes = [];

    /** @var mixed */
    private static $app = null;
    /** @var array<string,bool> */
    private static array $warnedRefusals = [];

    private function __construct()
    {
    }

    /**
     * Declare routes the framework cannot know about. Framework and declared
     * lists merge; neither silently replaces the other.
     *
     * @param mixed $routes
     */
    public static function registerRoutes($routes): void
    {
        try {
            if (!is_array($routes) && !$routes instanceof \Traversable) {
                return;
            }
            $clean = [];
            foreach ($routes as $raw) {
                if (!is_string($raw)) {
                    continue;
                }
                $label = self::declaredLabel($raw);
                if ($label !== null && !in_array($label, $clean, true)) {
                    $clean[] = $label;
                }
            }
            self::$declaredRoutes = $clean;
        } catch (\Throwable $e) {
            // A declaration is optional metadata and must never disturb the host.
        }
    }

    /**
     * Remember the app, but do not walk it. The route table is read only when a
     * snapshot is built, so startup and requests never pay for the inventory.
     *
     * @param mixed $app
     */
    public static function registerApp($app): void
    {
        try {
            if (!is_object($app)) {
                return;
            }
            self::$app = $app;
        } catch (\Throwable $e) {
            // A host object with surprising behaviour is never allowed outward.
        }
    }

    public static function resetForTests(): void
    {
        self::$declaredRoutes = [];
        self::$app = null;
        self::$warnedRefusals = [];
    }

    public static function normalizePartName(mixed $raw, bool $warnDeclared = false): ?string
    {
        try {
            if (!is_string($raw)) {
                return null;
            }
            $label = trim($raw);
            if (strlen($label) > self::MAX_PART_NAME) {
                if ($warnDeclared) {
                    self::warnRefusalOnce('too-long');
                }
                return null;
            }
            if ($label === '' || Pii::checkRouteLabel($label) !== null) {
                if ($warnDeclared) {
                    self::warnRefusalOnce('unsafe');
                }
                return null;
            }

            return $label;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return array{status:string,source?:string,entries:list<array{label:string,from:string}>,total:int} */
    public static function routeListReport(): array
    {
        try {
            if (!self::enabled()) {
                return ['status' => 'off', 'entries' => [], 'total' => 0];
            }

            $framework = self::$app === null
                ? ['status' => 'unsupported', 'labels' => []]
                : self::readFrameworkRoutes(self::$app);

            /** @var array<string,string> $from */
            $from = [];
            foreach ($framework['labels'] as $label) {
                $from[$label] = 'framework';
            }
            foreach (self::$declaredRoutes as $label) {
                $from[$label] = isset($from[$label]) ? 'both' : 'declared';
            }
            ksort($from, SORT_STRING);

            $entries = [];
            foreach (array_slice($from, 0, self::MAX_ROUTE_LIST_ENTRIES, true) as $label => $origin) {
                $entries[] = ['label' => $label, 'from' => $origin];
            }
            $report = [
                'status' => $framework['status'],
                'entries' => $entries,
                'total' => count($from),
            ];
            if (isset($framework['source'])
                && in_array($framework['source'], self::ROUTE_SOURCE_WORDS, true)) {
                $report['source'] = $framework['source'];
            }

            return $report;
        } catch (\Throwable $e) {
            return ['status' => 'unreadable', 'entries' => [], 'total' => 0];
        }
    }

    /** @return array{status:string,source?:string,entries:list<array{label:string,from:string}>,total:int} */
    public static function routeListForSnapshot(): array
    {
        try {
            return self::routeListReport();
        } catch (\Throwable $e) {
            return ['status' => 'unreadable', 'entries' => [], 'total' => 0];
        }
    }

    /**
     * @param mixed $app
     * @return array{status:string,source?:string,labels:list<string>}
     */
    public static function readFrameworkRoutes($app): array
    {
        try {
            if (!is_object($app)) {
                return ['status' => 'unsupported', 'labels' => []];
            }

            // Slim exposes the collector directly on the application.
            // method_exists is intentional: Laravel's container __call makes
            // arbitrary method names look callable, including Slim's accessor.
            if (method_exists($app, 'getRouteCollector')
                && is_callable([$app, 'getRouteCollector'])) {
                return self::readSlim($app);
            }

            // Laravel's Application is a container. Asking it for "router" is
            // the framework-supported route to the RouteCollection.
            if (is_callable([$app, 'make']) || is_callable([$app, 'getRoutes'])) {
                return self::readLaravel($app);
            }

            return ['status' => 'unsupported', 'labels' => []];
        } catch (\Throwable $e) {
            // Recognition may itself invoke a host autoloader. Never leak it.
            return ['status' => 'unreadable', 'labels' => []];
        }
    }

    /** @param mixed $app @return array{status:string,source:string,labels:list<string>} */
    private static function readSlim($app): array
    {
        try {
            $collector = $app->getRouteCollector();
            if (!is_object($collector) || !is_callable([$collector, 'getRoutes'])) {
                return self::unreadable('slim');
            }
            $routes = $collector->getRoutes();
            if (!is_array($routes) && !$routes instanceof \Traversable) {
                return self::unreadable('slim');
            }

            $labels = [];
            foreach ($routes as $route) {
                if (!is_object($route)
                    || !is_callable([$route, 'getMethods'])
                    || !is_callable([$route, 'getPattern'])) {
                    return self::unreadable('slim');
                }
                $methods = $route->getMethods();
                $pattern = $route->getPattern();
                if (!is_array($methods) || !is_string($pattern)) {
                    return self::unreadable('slim');
                }
                foreach ($methods as $method) {
                    if (!is_string($method)) {
                        return self::unreadable('slim');
                    }
                    $label = self::frameworkLabel($method, $pattern, 'slim');
                    if ($label !== null) {
                        $labels[] = $label;
                    }
                }
            }

            return ['status' => 'read', 'source' => 'slim', 'labels' => self::uniqueSorted($labels)];
        } catch (\Throwable $e) {
            return self::unreadable('slim');
        }
    }

    /** @param mixed $app @return array{status:string,source:string,labels:list<string>} */
    private static function readLaravel($app): array
    {
        try {
            $router = $app;
            if (is_callable([$app, 'make'])) {
                $router = $app->make('router');
            }
            if (!is_object($router) || !is_callable([$router, 'getRoutes'])) {
                return self::unreadable('laravel');
            }
            $routes = $router->getRoutes();
            if (!is_array($routes) && !$routes instanceof \Traversable) {
                return self::unreadable('laravel');
            }

            $labels = [];
            foreach ($routes as $route) {
                if (!is_object($route)
                    || !is_callable([$route, 'methods'])
                    || !is_callable([$route, 'uri'])) {
                    return self::unreadable('laravel');
                }
                $methods = $route->methods();
                $uri = $route->uri();
                if (!is_array($methods) || !is_string($uri)) {
                    return self::unreadable('laravel');
                }
                if (self::isLaravelInternal($uri) || preg_match('/\{[^{}]*\?\}/', $uri) === 1) {
                    continue;
                }
                foreach ($methods as $method) {
                    if (!is_string($method)) {
                        return self::unreadable('laravel');
                    }
                    $label = self::frameworkLabel($method, '/' . ltrim($uri, '/'), 'laravel');
                    if ($label !== null) {
                        $labels[] = $label;
                    }
                }
            }

            return [
                'status' => 'read',
                'source' => 'laravel',
                'labels' => self::dropAutomaticHead(self::uniqueSorted($labels)),
            ];
        } catch (\Throwable $e) {
            return self::unreadable('laravel');
        }
    }

    private static function enabled(): bool
    {
        try {
            $value = RuntimeFlags::env('BOOSTHIS_ROUTE_LIST');
            if ($value === null) {
                return true;
            }

            return !in_array(strtolower(trim($value)), ['0', 'false', 'off', 'no'], true);
        } catch (\Throwable $e) {
            return true;
        }
    }

    private static function declaredLabel(string $raw): ?string
    {
        $text = trim($raw);
        if ($text === '') {
            return null;
        }
        $space = strpos($text, ' ');

        return $space === false
            ? self::frameworkLabel('GET', $text, 'declared')
            : self::frameworkLabel(substr($text, 0, $space), substr($text, $space + 1), 'declared');
    }

    private static function frameworkLabel(string $method, string $path, string $source): ?string
    {
        try {
            $method = strtoupper(trim($method));
            $path = trim($path);
            if (!in_array($method, self::HTTP_METHODS, true) || $path === '') {
                return null;
            }
            if (!str_starts_with($path, '/')) {
                $path = '/' . $path;
            }
            if (str_contains($path, '?')
                || str_contains($path, '#')
                || self::isKitPath($path)) {
                return null;
            }

            // Slim/Laravel keep {name} in the matched request label. Accept
            // exactly that parameter syntax, but refuse regexes, wildcards and
            // optional fragments that would leave pattern language on the wire.
            $withoutParams = preg_replace('/\{[A-Za-z_][A-Za-z0-9_]*\}/', ':id', $path);
            if (!is_string($withoutParams)
                || preg_match('/[{}()[\]|*+\\\\]/', $withoutParams) === 1) {
                return null;
            }
            if ($source === 'declared') {
                $withoutParams = preg_replace('/:[A-Za-z_][A-Za-z0-9_]*/', ':id', $withoutParams);
            }
            if (!is_string($withoutParams)
                || preg_match('#^/[A-Za-z0-9_./:~%-]*$#', $withoutParams) !== 1) {
                return null;
            }

            $label = self::normalizePartName(
                $method . ' ' . $withoutParams,
                $source === 'declared'
            );

            return $label;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function isKitPath(string $path): bool
    {
        return $path === Page::PAGE_PATH
            || str_starts_with($path, Page::PAGE_PATH . '/');
    }

    private static function isLaravelInternal(string $uri): bool
    {
        $uri = ltrim($uri, '/');

        return $uri === '_ignition'
            || str_starts_with($uri, '_ignition/')
            || $uri === 'sanctum'
            || str_starts_with($uri, 'sanctum/')
            || $uri === '_debugbar'
            || str_starts_with($uri, '_debugbar/');
    }

    /** @param list<string> $labels @return list<string> */
    private static function uniqueSorted(array $labels): array
    {
        $labels = array_values(array_unique($labels));
        sort($labels, SORT_STRING);

        return $labels;
    }

    /** @param list<string> $labels @return list<string> */
    private static function dropAutomaticHead(array $labels): array
    {
        $gets = [];
        foreach ($labels as $label) {
            if (str_starts_with($label, 'GET ')) {
                $gets[substr($label, 4)] = true;
            }
        }

        return array_values(array_filter(
            $labels,
            static fn (string $label): bool => !str_starts_with($label, 'HEAD ')
                || !isset($gets[substr($label, 5)])
        ));
    }

    /** @return array{status:string,source:string,labels:list<string>} */
    private static function unreadable(string $source): array
    {
        return ['status' => 'unreadable', 'source' => $source, 'labels' => []];
    }

    private static function warnRefusalOnce(string $reason): void
    {
        if (isset(self::$warnedRefusals[$reason])) {
            return;
        }
        self::$warnedRefusals[$reason] = true;
        $detail = $reason === 'too-long'
            ? 'its length exceeds ' . self::MAX_PART_NAME
                . ' characters. Declare a route name of ' . self::MAX_PART_NAME
                . ' characters or fewer.'
            : 'it is empty or carries a value. Declare a non-empty, code-defined '
                . 'route template such as /users/:id.';
        StartAnnounce::sayAfterStartupLine(
            '[boosthis] Boosthis refused a declared route name because ' . $detail
        );
    }
}