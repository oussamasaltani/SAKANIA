<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * AI-provider classification and the numeric-only call aggregate.
 *
 * PHP cannot safely replace every HTTP client. Native cURL is watched when the
 * app hands its completed handle to Boosthis, and Guzzle/stream users can feed
 * the same observation explicitly. Destinations are reduced to a fixed wire
 * code in this class and are never stored or returned.
 */
final class AiCalls
{
    /**
     * APPEND ONLY — a provider's 1-based position is its wire code.
     *
     * @var list<array{id:string,hosts:list<string>}>
     */
    public const PROVIDERS = [
        ['id' => 'openai', 'hosts' => ['api.openai.com']],
        ['id' => 'anthropic', 'hosts' => ['api.anthropic.com']],
        ['id' => 'google', 'hosts' => [
            'generativelanguage.googleapis.com',
            'aiplatform.googleapis.com',
            '.aiplatform.googleapis.com',
        ]],
        ['id' => 'azure-openai', 'hosts' => ['.openai.azure.com', '.cognitiveservices.azure.com']],
        ['id' => 'aws-bedrock', 'hosts' => ['.amazonaws.com']],
        ['id' => 'mistral', 'hosts' => ['api.mistral.ai', 'codestral.mistral.ai']],
        ['id' => 'cohere', 'hosts' => ['api.cohere.ai', 'api.cohere.com']],
        ['id' => 'groq', 'hosts' => ['api.groq.com']],
        ['id' => 'together', 'hosts' => ['api.together.xyz', 'api.together.ai']],
        ['id' => 'perplexity', 'hosts' => ['api.perplexity.ai']],
        ['id' => 'deepseek', 'hosts' => ['api.deepseek.com']],
        ['id' => 'xai', 'hosts' => ['api.x.ai']],
        ['id' => 'fireworks', 'hosts' => ['api.fireworks.ai']],
        ['id' => 'openrouter', 'hosts' => ['openrouter.ai']],
        ['id' => 'replicate', 'hosts' => ['api.replicate.com']],
        ['id' => 'huggingface', 'hosts' => [
            'api-inference.huggingface.co',
            'router.huggingface.co',
        ]],
        ['id' => 'voyage', 'hosts' => ['api.voyageai.com']],
        ['id' => 'cerebras', 'hosts' => ['api.cerebras.ai']],
        ['id' => 'declared', 'hosts' => []],
    ];

    public const DECLARED_PROVIDER_CODE = 19;
    public const MAX_DECLARED_ENDPOINTS = 8;

    private const KEY = 'meter_ai_calls';
    private const RING_CAP = 256;
    private const SHAPED_PATHS = [
        '/v1/chat/completions',
        '/v1/completions',
        '/v1/responses',
        '/v1/embeddings',
        '/v1/messages',
        '/api/generate',
        '/api/chat',
        '/api/embeddings',
        '/generate_stream',
    ];
    private const AWS_BEDROCK_PREFIXES = ['bedrock-runtime.', 'bedrock.'];

    /** @var list<string> */
    private static array $declaredHosts = [];
    private static bool $configured = false;
    private static bool $observationArmed = false;
    /** @var null|callable(string):bool */
    private static $clientPresenceProbe = null;

    private function __construct()
    {
    }

    /**
     * Merge code declarations with BOOSTHIS_AI_ENDPOINTS. Exact hosts only;
     * paths, credentials and ports are discarded before anything is retained.
     *
     * @param mixed $endpoints a string or list of endpoint strings
     */
    public static function configure($endpoints = null): int
    {
        $env = RuntimeFlags::env('BOOSTHIS_AI_ENDPOINTS');
        $candidates = $env === null ? [] : explode(',', $env);
        if (is_array($endpoints)) {
            $candidates = array_merge($candidates, $endpoints);
        } elseif ($endpoints !== null) {
            $candidates[] = $endpoints;
        }

        $hosts = [];
        foreach ($candidates as $candidate) {
            $host = self::normaliseEndpoint($candidate);
            if ($host === null || in_array($host, $hosts, true)) {
                continue;
            }
            $hosts[] = $host;
            if (count($hosts) >= self::MAX_DECLARED_ENDPOINTS) {
                break;
            }
        }
        self::$declaredHosts = $hosts;
        self::$configured = true;

        return count($hosts);
    }

    /** Mark the outbound observation point as installed. */
    public static function arm(): void
    {
        if (!RuntimeFlags::disabled()) {
            self::$observationArmed = true;
        }
    }

    /**
     * Deterministic client-presence seam for the dependency-free test suite.
     *
     * @param null|callable(string):bool $probe
     */
    public static function setClientPresenceProbeForTests(?callable $probe): void
    {
        self::$clientPresenceProbe = $probe;
    }

    /** Record one completed HTTP call without accepting headers or a body. */
    public static function record(string $url, float $durationMs, int $statusCode, string $method): void
    {
        if (RuntimeFlags::disabled()) {
            return;
        }
        self::$observationArmed = true;
        Safe::fire(static function () use ($url, $durationMs, $statusCode, $method): void {
            $parts = @parse_url($url);
            if (!is_array($parts) || !isset($parts['host'])) {
                return;
            }
            $host = strtolower((string) $parts['host']);
            if ($host === '' || self::isOwnEndpoint($host)) {
                return;
            }

            $provider = self::providerCode($host);
            if ($provider === 0) {
                $provider = self::declaredCode($host);
            }
            if ($provider === 0) {
                $path = strtolower((string) ($parts['path'] ?? ''));
                $path = rtrim($path, '/');
                if (strtoupper($method) === 'POST' && in_array($path, self::SHAPED_PATHS, true)) {
                    Store::update(self::KEY, static function ($cur): array {
                        $s = is_array($cur) ? $cur : [];
                        $s['unclassifiedCalls'] = (int) ($s['unclassifiedCalls'] ?? 0) + 1;
                        return $s;
                    }, []);
                }
                return;
            }

            $duration = is_finite($durationMs) && $durationMs >= 0
                ? (int) round($durationMs)
                : null;
            Store::update(self::KEY, static function ($cur) use ($provider, $duration, $statusCode): array {
                $s = is_array($cur) ? $cur : [];
                $providers = is_array($s['providers'] ?? null) ? $s['providers'] : [];
                $key = (string) $provider;
                $providers[$key] = (int) ($providers[$key] ?? 0) + 1;
                $durations = is_array($s['durations'] ?? null) ? $s['durations'] : [];
                if ($duration !== null) {
                    $durations[] = $duration;
                    if (count($durations) > self::RING_CAP) {
                        $durations = array_slice($durations, -self::RING_CAP);
                    }
                }
                $s['callCount'] = (int) ($s['callCount'] ?? 0) + 1;
                $s['providers'] = $providers;
                $s['durations'] = array_values($durations);
                if ($duration !== null) {
                    $s['worstMs'] = max((int) ($s['worstMs'] ?? 0), $duration);
                }
                $s['failCount'] = (int) ($s['failCount'] ?? 0)
                    + (($statusCode <= 0 || $statusCode >= 400) ? 1 : 0);
                $s['rateLimitedCount'] = (int) ($s['rateLimitedCount'] ?? 0)
                    + ($statusCode === 429 ? 1 : 0);
                $s['declaredCalls'] = (int) ($s['declaredCalls'] ?? 0)
                    + ($provider === self::DECLARED_PROVIDER_CODE ? 1 : 0);
                return $s;
            }, []);
        });
    }

    /** The allowlisted numeric-only axis, or null before any relevant call. */
    public static function read(): ?array
    {
        if (RuntimeFlags::disabled() || !self::$observationArmed) {
            return null;
        }
        $s = Store::get(self::KEY, []);
        if (!is_array($s)) {
            return null;
        }
        $calls = (int) ($s['callCount'] ?? 0);
        $unclassified = (int) ($s['unclassifiedCalls'] ?? 0);
        $unwatched = self::unwatchedClientCount();
        if ($calls === 0 && $unclassified === 0) {
            return $unwatched > 0
                ? [
                    'measurable' => 0,
                    'rating' => 'pending',
                    'unwatchedClients' => $unwatched,
                ]
                : null;
        }
        if ($calls === 0) {
            return [
                'measurable' => 0,
                'rating' => 'pending',
                'unclassifiedCalls' => $unclassified,
                'unwatchedClients' => $unwatched,
            ];
        }

        $providers = is_array($s['providers'] ?? null) ? $s['providers'] : [];
        $topProvider = 0;
        $topCalls = 0;
        foreach ($providers as $code => $count) {
            if ((int) $count > $topCalls) {
                $topCalls = (int) $count;
                $topProvider = (int) $code;
            }
        }
        $durations = is_array($s['durations'] ?? null) ? array_map('intval', $s['durations']) : [];
        sort($durations);
        $idx = max(0, (int) ceil(count($durations) * 0.75) - 1);

        $axis = [
            'callCount' => $calls,
            'providerCount' => count($providers),
            'topProvider' => $topProvider,
            'failCount' => (int) ($s['failCount'] ?? 0),
            'rateLimitedCount' => (int) ($s['rateLimitedCount'] ?? 0),
            'declaredCalls' => (int) ($s['declaredCalls'] ?? 0),
        ];
        if ($durations !== []) {
            $axis['p75Ms'] = (int) $durations[$idx];
            $axis['worstMs'] = (int) ($s['worstMs'] ?? max($durations));
        }
        if ($calls < MeterAxes::MIN_SAMPLES_FOR_AXES) {
            $axis['measurable'] = 0;
            $axis['rating'] = 'pending';
        } else {
            $failPct = 100.0 * (int) ($s['failCount'] ?? 0) / $calls;
            $score = Score::scoreMetric($failPct, 0.0, 10.0);
            $axis['measurable'] = 1;
            $axis['score'] = $score;
            $axis['rating'] = Score::getRating($score);
        }
        $axis['unwatchedClients'] = $unwatched;
        if ($unclassified > 0) {
            $axis['unclassifiedCalls'] = $unclassified;
        }

        return $axis;
    }

    public static function hasData(): bool
    {
        if (RuntimeFlags::disabled() || !self::$observationArmed) {
            return false;
        }
        $s = Store::get(self::KEY, []);
        return (is_array($s)
                && ((int) ($s['callCount'] ?? 0) > 0 || (int) ($s['unclassifiedCalls'] ?? 0) > 0))
            || self::unwatchedClientCount() > 0;
    }

    public static function clear(): void
    {
        Store::delete(self::KEY);
        self::$declaredHosts = [];
        self::$configured = false;
        self::$observationArmed = false;
        self::$clientPresenceProbe = null;
    }

    private static function providerCode(string $host): int
    {
        foreach (self::PROVIDERS as $i => $entry) {
            foreach ($entry['hosts'] as $suffix) {
                $hit = str_starts_with($suffix, '.')
                    ? ($host === substr($suffix, 1) || str_ends_with($host, $suffix))
                    : $host === $suffix;
                if (!$hit) {
                    continue;
                }
                if ($entry['id'] === 'aws-bedrock') {
                    $bedrock = false;
                    foreach (self::AWS_BEDROCK_PREFIXES as $prefix) {
                        $bedrock = $bedrock || str_starts_with($host, $prefix);
                    }
                    if (!$bedrock) {
                        continue;
                    }
                }
                return $i + 1;
            }
        }
        return 0;
    }

    private static function declaredCode(string $host): int
    {
        if (!self::$configured) {
            self::configure();
        }
        return in_array($host, self::$declaredHosts, true) ? self::DECLARED_PROVIDER_CODE : 0;
    }

    /** @param mixed $raw */
    private static function normaliseEndpoint($raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $value = trim(strtolower($raw));
        if ($value === '' || preg_match('/\s/', $value) === 1 || str_contains($value, '*')) {
            return null;
        }
        $candidate = str_contains($value, '://') ? $value : 'http://' . $value;
        $host = @parse_url($candidate, PHP_URL_HOST);
        if (!is_string($host) || $host === '' || strlen($host) > 253) {
            return null;
        }
        return strtolower(trim($host, '[]'));
    }

    private static function isOwnEndpoint(string $host): bool
    {
        $own = @parse_url(RuntimeFlags::apiBase(), PHP_URL_HOST);
        if (is_string($own) && strtolower($own) === $host) {
            return true;
        }
        return $host === 'boosthis.com' || str_ends_with($host, '.boosthis.com');
    }

    /**
     * Count loaded client libraries whose calls cannot be intercepted here.
     * Autoload is deliberately disabled: probing must not load customer code.
     */
    private static function unwatchedClientCount(): int
    {
        $libraries = [
            ['GuzzleHttp\\Client', 'GuzzleHttp\\ClientInterface'],
            ['Symfony\\Component\\HttpClient\\HttpClient', 'Symfony\\Contracts\\HttpClient\\HttpClientInterface'],
            ['Illuminate\\Http\\Client\\Factory', 'Illuminate\\Http\\Client\\PendingRequest'],
        ];
        $count = 0;
        foreach ($libraries as $classes) {
            foreach ($classes as $class) {
                $present = self::$clientPresenceProbe !== null
                    ? (bool) (self::$clientPresenceProbe)($class)
                    : (class_exists($class, false) || interface_exists($class, false));
                if ($present) {
                    $count++;
                    break;
                }
            }
        }
        return $count;
    }
}