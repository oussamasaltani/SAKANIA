<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * Outside-service and connect-phase readings.
 *
 * A destination exists on this stack only long enough to be matched against
 * closed tables. Store contains counts, durations and closed words/codes only.
 */
final class DependencyWork
{
    private const KEY = 'reading:dependencies';
    private const DISTANCE_KEY = 'reading:dependencyDistance';
    private const RING = 128;
    private const KINDS = ['signin', 'storage', 'payments', 'messaging', 'search', 'other'];
    private const KIND_CODES = [
        'storage' => 4,
        'payments' => 5,
        'signin' => 6,
        'messaging' => 6,
        'search' => 6,
        'other' => 6,
    ];

    /** @var array{count:int,failed:int,ms:float,kinds:array<string,int>}|null */
    private static ?array $request = null;

    private function __construct()
    {
    }

    public static function beginRequest(): void
    {
        self::$request = ['count' => 0, 'failed' => 0, 'ms' => 0.0, 'kinds' => []];
    }

    /**
     * Record one already-finished HTTP call. The host is classified and then
     * discarded; it is never captured by the Store update closure.
     */
    public static function record(
        string $host,
        float $durationMs,
        string $outcome = 'ok',
        ?float $setupMs = null,
        bool $newConnection = false
    ): void {
        if (RuntimeFlags::disabled()) {
            return;
        }
        Safe::fire(static function () use ($host, $durationMs, $outcome, $setupMs, $newConnection): void {
            $bare = self::bareHost($host);
            if ($bare === '' || self::isBoosthis($bare)) {
                return;
            }
            $code = self::distanceCodeFor($bare);
            $outside = !self::excludedFromOutside($bare);
            $kind = $outside ? self::kindFor($bare) : null;
            // Do not retain $bare beyond classification.
            unset($bare);
            $duration = (int) round(max(0.0, $durationMs));
            $bucket = $outcome === 'timedout' ? 'timedOut' : ($outcome === 'failed' ? 'failed' : 'ok');

            if ($kind !== null) {
                Store::update(self::KEY, static function ($cur) use ($kind, $duration, $bucket): array {
                    $state = is_array($cur) ? $cur : [];
                    $groups = is_array($state['groups'] ?? null) ? $state['groups'] : [];
                    $g = is_array($groups[$kind] ?? null) ? $groups[$kind] : [];
                    $g['calls'] = (int) ($g['calls'] ?? 0) + 1;
                    $g['ok'] = (int) ($g['ok'] ?? 0) + ($bucket === 'ok' ? 1 : 0);
                    $g['failed'] = (int) ($g['failed'] ?? 0) + ($bucket === 'failed' ? 1 : 0);
                    $g['timedOut'] = (int) ($g['timedOut'] ?? 0) + ($bucket === 'timedOut' ? 1 : 0);
                    $g['requests'] = (int) ($g['requests'] ?? 0);
                    $g['worstMs'] = max((int) ($g['worstMs'] ?? 0), $duration);
                    $ring = is_array($g['durations'] ?? null) ? $g['durations'] : [];
                    $ring[] = $duration;
                    $g['durations'] = array_values(array_slice($ring, -self::RING));
                    $groups[$kind] = $g;
                    $state['groups'] = $groups;
                    return $state;
                }, []);

                if (self::$request !== null) {
                    self::$request['count']++;
                    self::$request['failed'] += $bucket === 'ok' ? 0 : 1;
                    self::$request['ms'] += $duration;
                    self::$request['kinds'][$kind] = 1;
                }
            }

            if ($newConnection && $setupMs !== null && is_finite($setupMs) && $setupMs >= 0) {
                $setup = round($setupMs * 10) / 10.0;
                Store::update(self::DISTANCE_KEY, static function ($cur) use ($code, $setup): array {
                    $state = is_array($cur) ? $cur : [];
                    $rings = is_array($state['kinds'] ?? null) ? $state['kinds'] : [];
                    $ring = is_array($rings[(string) $code] ?? null) ? $rings[(string) $code] : [];
                    $ring[] = $setup;
                    $rings[(string) $code] = array_values(array_slice($ring, -self::RING));
                    $state['kinds'] = $rings;
                    $state['newConnections'] = (int) ($state['newConnections'] ?? 0) + 1;
                    return $state;
                }, []);
            }
        });
    }

    /** Fold the one request that supplied the denominator into durable totals. */
    public static function endRequest(float $requestMs): void
    {
        $request = self::$request;
        self::$request = null;
        if ($request === null || $request['count'] === 0 || RuntimeFlags::disabled()) {
            return;
        }
        Safe::fire(static function () use ($request, $requestMs): void {
            $wall = max(0.0, $requestMs);
            Store::update(self::KEY, static function ($cur) use ($request, $wall): array {
                $state = is_array($cur) ? $cur : [];
                $state['watchedRequests'] = (int) ($state['watchedRequests'] ?? 0) + 1;
                $state['watchedRequestMs'] = (float) ($state['watchedRequestMs'] ?? 0) + $wall;
                $state['depMs'] = (float) ($state['depMs'] ?? 0) + min($wall, $request['ms']);
                $state['callCount'] = (int) ($state['callCount'] ?? 0) + $request['count'];
                $groups = is_array($state['groups'] ?? null) ? $state['groups'] : [];
                foreach (array_keys($request['kinds']) as $kind) {
                    if (isset($groups[$kind]) && is_array($groups[$kind])) {
                        $groups[$kind]['requests'] = (int) ($groups[$kind]['requests'] ?? 0) + 1;
                    }
                }
                $state['groups'] = $groups;
                return $state;
            }, []);
        });
    }

    /** Counts still live on the current request, before endRequest() clears it. */
    public static function currentRequestCounts(): array
    {
        return [
            'count' => (int) (self::$request['count'] ?? 0),
            'failed' => (int) (self::$request['failed'] ?? 0),
        ];
    }

    /** @return array<string,mixed>|null */
    public static function dependencies(): ?array
    {
        return Safe::run(static function (): ?array {
            $state = Store::get(self::KEY, []);
            $state = is_array($state) ? $state : [];
            $groupsState = is_array($state['groups'] ?? null) ? $state['groups'] : [];
            $inAppSignin = self::inAppSignin() ? 1 : 0;
            $calls = 0;
            foreach ($groupsState as $g) {
                if (is_array($g)) {
                    $calls += (int) ($g['calls'] ?? 0);
                }
            }
            if ($calls === 0 && $inAppSignin === 0) {
                return null;
            }
            $watched = (int) ($state['watchedRequests'] ?? 0);
            if ($watched < 5) {
                return [
                    'score' => null, 'rating' => 'pending', 'measurable' => 0,
                    'waitPct' => 0, 'dbPct' => 0, 'aiPct' => 0, 'selfPct' => 0,
                    'callCount' => $calls, 'watchedRequests' => $watched,
                    'failCount' => 0, 'timeoutCount' => 0, 'failPct' => 0,
                    'p75Ms' => 0, 'worstMs' => 0, 'kindCount' => 0,
                    'flakyKinds' => 0, 'unknownCalls' => 0, 'signinCalls' => 0,
                    'signinP75Ms' => null, 'signinFailed' => 0,
                    'inAppSignin' => $inAppSignin, 'groups' => [],
                ];
            }

            $groups = [];
            $failed = $timeouts = $p75 = $worst = $flaky = $unknown = $signin = $signinFailed = 0;
            $signinP75 = null;
            foreach (self::KINDS as $kind) {
                $g = $groupsState[$kind] ?? null;
                if (!is_array($g) || (int) ($g['calls'] ?? 0) === 0) {
                    continue;
                }
                $durations = is_array($g['durations'] ?? null) ? $g['durations'] : [];
                $gp75 = self::percentile($durations, 0.75);
                $row = [
                    'kind' => $kind,
                    'calls' => (int) $g['calls'],
                    'ok' => (int) ($g['ok'] ?? 0),
                    'failed' => (int) ($g['failed'] ?? 0),
                    'timedOut' => (int) ($g['timedOut'] ?? 0),
                    'requests' => (int) ($g['requests'] ?? 0),
                    'p75Ms' => $gp75,
                    'worstMs' => (int) ($g['worstMs'] ?? 0),
                    'flaky' => (int) ($g['ok'] ?? 0) > 0
                        && (int) ($g['failed'] ?? 0) + (int) ($g['timedOut'] ?? 0) > 0 ? 1 : 0,
                ];
                $groups[] = $row;
                $failed += $row['failed'];
                $timeouts += $row['timedOut'];
                $p75 = max($p75, $gp75);
                $worst = max($worst, $row['worstMs']);
                $flaky += $row['flaky'];
                if ($kind === 'other') {
                    $unknown += $row['calls'];
                } elseif ($kind === 'signin') {
                    $signin = $row['calls'];
                    $signinP75 = $gp75;
                    $signinFailed = $row['failed'] + $row['timedOut'];
                }
            }
            $wall = (float) ($state['watchedRequestMs'] ?? 0);
            $waitPct = self::pct((float) ($state['depMs'] ?? 0), $wall);
            $dbPct = self::pct((float) ($state['dbMs'] ?? 0), $wall);
            $aiPct = self::pct((float) ($state['aiMs'] ?? 0), $wall);
            $failPct = $calls > 0 ? self::clamp1(($failed + $timeouts) / $calls * 100) : 0.0;
            $score = min(Score::scoreMetric($waitPct, 25, 75), Score::scoreMetric($failPct, 0.5, 10));
            return [
                'score' => $score, 'rating' => Score::getRating($score), 'measurable' => 1,
                'waitPct' => $waitPct, 'dbPct' => $dbPct, 'aiPct' => $aiPct,
                'selfPct' => self::clamp1(max(0, 100 - $waitPct - $dbPct - $aiPct)),
                'callCount' => $calls, 'watchedRequests' => $watched,
                'failCount' => $failed, 'timeoutCount' => $timeouts, 'failPct' => $failPct,
                'p75Ms' => $p75, 'worstMs' => $worst, 'kindCount' => count($groups),
                'flakyKinds' => $flaky, 'unknownCalls' => $unknown, 'signinCalls' => $signin,
                'signinP75Ms' => $signinP75, 'signinFailed' => $signinFailed,
                'inAppSignin' => $inAppSignin, 'groups' => $groups,
            ];
        }, null);
    }

    /** @return array<string,mixed>|null */
    public static function distance(?float $typicalRequestMs): ?array
    {
        return Safe::run(static function () use ($typicalRequestMs): ?array {
            $state = Store::get(self::DISTANCE_KEY, []);
            if (!is_array($state) || (int) ($state['newConnections'] ?? 0) === 0) {
                return null;
            }
            $typical = $typicalRequestMs !== null && $typicalRequestMs > 0 ? $typicalRequestMs : 0.0;
            $rows = [];
            $judged = $worstKind = 0;
            $worstSetup = 0.0;
            $unjudged = 0;
            $unjudgedWorstSetup = -1.0;
            $unjudgedWorstKind = 0;
            $unjudgedWorstConnections = 0;
            foreach ((array) ($state['kinds'] ?? []) as $code => $ring) {
                if (!is_array($ring) || $ring === []) {
                    continue;
                }
                $setup = self::median($ring);
                $row = ['kind' => (int) $code, 'connections' => count($ring), 'setupMs' => $setup];
                if ($typical > 0) {
                    $row['sharePct'] = self::clamp1($setup / $typical * 100);
                }
                $rows[] = $row;
                if (count($ring) >= 5) {
                    $judged++;
                    if ($setup > $worstSetup) {
                        $worstSetup = $setup;
                        $worstKind = (int) $code;
                    }
                } else {
                    // THE EXCLUDED SIDE OF THE SAME SWEEP. The connection
                    // floor is right, but publishing the smaller judged
                    // figure under the word "worst" while holding a bigger
                    // one makes the word false.
                    $unjudged++;
                    if ($setup > $unjudgedWorstSetup) {
                        $unjudgedWorstSetup = $setup;
                        $unjudgedWorstKind = (int) $code;
                        $unjudgedWorstConnections = count($ring);
                    }
                }
            }
            usort($rows, static fn (array $a, array $b): int =>
                $b['setupMs'] <=> $a['setupMs'] ?: $a['kind'] <=> $b['kind']);
            $base = [
                'newConnections' => (int) $state['newConnections'], 'minConnections' => 5,
                'judgedKinds' => $judged, 'worstKind' => $worstKind,
                'worstSetupMs' => $worstSetup, 'worstSharePct' => $typical > 0 && $judged > 0
                    ? self::clamp1($worstSetup / $typical * 100) : null,
                'typicalRequestMs' => (int) round($typical), 'hostArea' => self::hostArea(),
                'ownBackendOnly' => 0, 'kinds' => $rows,
                // Only sent when it is BIGGER than the published worst — a
                // smaller excluded reading changes nothing about what the
                // word "worst" means.
                'unjudgedKinds' => $unjudged,
                'unjudgedWorstSetupMs' => $unjudgedWorstSetup > $worstSetup ? $unjudgedWorstSetup : null,
                'unjudgedWorstKind' => $unjudgedWorstSetup > $worstSetup ? $unjudgedWorstKind : null,
                'unjudgedWorstConnections' => $unjudgedWorstSetup > $worstSetup ? $unjudgedWorstConnections : null,
            ];
            if ($judged === 0) {
                return ['score' => null, 'rating' => 'pending', 'measurable' => 0] + $base;
            }
            $score = Score::scoreMetric($worstSetup, 20, 150);
            return ['score' => $score, 'rating' => Score::getRating($score), 'measurable' => 1] + $base;
        }, null);
    }

    public static function clear(): void
    {
        self::$request = null;
        Store::delete(self::KEY);
        Store::delete(self::DISTANCE_KEY);
    }

    private static function pct(float $part, float $whole): float
    {
        return $whole > 0 ? self::clamp1($part / $whole * 100) : 0.0;
    }

    private static function clamp1(float $value): float
    {
        return round(max(0, min(100, $value)) * 10) / 10.0;
    }

    /** @param list<int|float> $values */
    private static function percentile(array $values, float $p): int
    {
        if ($values === []) {
            return 0;
        }
        sort($values);
        return (int) round($values[max(0, (int) ceil($p * count($values)) - 1)]);
    }

    /** @param list<int|float> $values */
    private static function median(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);
        $value = $n % 2 === 0 ? ($values[$mid - 1] + $values[$mid]) / 2 : $values[$mid];
        return round($value * 10) / 10.0;
    }

    private static function bareHost(string $input): string
    {
        $host = parse_url(str_contains($input, '://') ? $input : 'https://' . $input, PHP_URL_HOST);
        return is_string($host) ? strtolower(trim($host, '.')) : '';
    }

    private static function domain(string $host, string $domain): bool
    {
        return $host === $domain || str_ends_with($host, '.' . $domain);
    }

    private static function isBoosthis(string $host): bool
    {
        return self::domain($host, 'boosthis.com');
    }

    private static function excludedFromOutside(string $host): bool
    {
        foreach ([
            'supabase.co', 'supabase.com', 'firebaseio.com',
            'neon.tech', 'turso.io', 'libsql.com', 'planetscale.com',
            'openai.com', 'anthropic.com', 'groq.com', 'mistral.ai',
            'generativelanguage.googleapis.com', 'api.cohere.ai',
        ] as $domain) {
            if (self::domain($host, $domain)) {
                return true;
            }
        }
        return false;
    }

    private static function distanceCodeFor(string $host): int
    {
        foreach (['supabase.co', 'supabase.com', 'firebaseio.com', 'neon.tech',
            'turso.io', 'libsql.com', 'planetscale.com'] as $domain) {
            if (self::domain($host, $domain)) {
                return 2;
            }
        }
        foreach (['openai.com', 'anthropic.com', 'groq.com', 'mistral.ai',
            'generativelanguage.googleapis.com', 'api.cohere.ai'] as $domain) {
            if (self::domain($host, $domain)) {
                return 3;
            }
        }
        return self::KIND_CODES[self::kindFor($host)];
    }

    private static function kindFor(string $host): string
    {
        $tables = [
            'signin' => ['clerk.com', 'clerk.accounts.dev', 'auth0.com', 'okta.com',
                'accounts.google.com', 'login.microsoftonline.com', 'appleid.apple.com',
                'amazoncognito.com', 'workos.com', 'stytch.com', 'kinde.com', 'descope.com'],
            'storage' => ['s3.amazonaws.com', 'storage.googleapis.com', 'blob.core.windows.net',
                'r2.cloudflarestorage.com', 'digitaloceanspaces.com', 'backblazeb2.com',
                'cloudinary.com', 'imagekit.io', 'uploadthing.com', 'uploadcare.com'],
            'payments' => ['stripe.com', 'paypal.com', 'adyen.com', 'checkout.com',
                'razorpay.com', 'paddle.com', 'squareup.com', 'braintreegateway.com',
                'lemonsqueezy.com', 'mollie.com', 'paystack.co', 'flutterwave.com'],
            'messaging' => ['resend.com', 'sendgrid.com', 'mailgun.net', 'postmarkapp.com',
                'mailjet.com', 'twilio.com', 'vonage.com', 'slack.com', 'discord.com',
                'fcm.googleapis.com', 'onesignal.com', 'knock.app', 'courier.com'],
            'search' => ['pinecone.io', 'weaviate.network', 'weaviate.cloud', 'qdrant.io',
                'qdrant.tech', 'zillizcloud.com', 'trychroma.com', 'turbopuffer.com',
                'vectara.io', 'algolia.net', 'typesense.net', 'meilisearch.io',
                'elastic-cloud.com'],
        ];
        foreach ($tables as $kind => $domains) {
            foreach ($domains as $domain) {
                if (self::domain($host, $domain)) {
                    return $kind;
                }
            }
        }
        if (str_ends_with($host, '.amazonaws.com')
            && (str_starts_with($host, 's3.') || str_contains($host, '.s3.'))) {
            return 'storage';
        }
        return 'other';
    }

    private static function inAppSignin(): bool
    {
        foreach ([
            'Laravel\\Sanctum\\Sanctum', 'Laravel\\Passport\\Passport',
            'League\\OAuth2\\Server\\AuthorizationServer', 'Hybridauth\\Hybridauth',
        ] as $class) {
            if (class_exists($class, false)) {
                return true;
            }
        }
        return false;
    }

    /** Platform-declared region -> closed area code; the declaration is dropped. */
    private static function hostArea(): int
    {
        $raw = '';
        foreach ([
            'VERCEL_REGION', 'AWS_REGION', 'AWS_DEFAULT_REGION', 'FUNCTION_REGION',
            'GOOGLE_CLOUD_REGION', 'REGION_NAME', 'FLY_REGION',
            'RAILWAY_REPLICA_REGION', 'RENDER_REGION',
        ] as $name) {
            $value = getenv($name);
            if (is_string($value) && trim($value) !== '') {
                $raw = strtolower(trim($value));
                break;
            }
        }
        if ($raw === '') {
            return 0;
        }
        $prefixes = [
            'us-east' => 2, 'ca-central' => 2, 'northamerica-northeast' => 2, 'eastus' => 2,
            'us-west' => 3, 'ca-west' => 3, 'westus' => 3,
            'us-central' => 4, 'centralus' => 4, 'northcentralus' => 4,
            'southcentralus' => 4, 'westcentralus' => 4,
            'sa-east' => 5, 'southamerica-' => 5, 'brazil' => 5, 'chile' => 5,
            'eu-north' => 7, 'europe-north' => 7, 'sweden' => 7, 'norway' => 7,
            'eu-' => 6, 'europe-' => 6, 'northeurope' => 6, 'westeurope' => 6,
            'uk' => 6, 'france' => 6, 'germany' => 6, 'switzerland' => 6,
            'italy' => 6, 'spain' => 6, 'poland' => 6,
            'ap-south-' => 9, 'asia-south' => 9, 'centralindia' => 9,
            'southindia' => 9, 'westindia' => 9, 'jioindia' => 9,
            'ap-southeast-2' => 12, 'ap-southeast-4' => 12, 'australia' => 12,
            'ap-' => 8, 'asia-' => 8, 'eastasia' => 8, 'southeastasia' => 8,
            'japan' => 8, 'korea' => 8,
            'me-' => 10, 'il-' => 10, 'uae' => 10, 'qatar' => 10, 'israel' => 10,
            'af-' => 11, 'africa-' => 11, 'southafrica' => 11,
        ];
        foreach ($prefixes as $prefix => $code) {
            if (str_starts_with($raw, $prefix)) {
                return $code;
            }
        }
        $airport = preg_replace('/[0-9]+$/', '', $raw);
        $codes = [
            'iad' => 2, 'bos' => 2, 'ewr' => 2, 'atl' => 2, 'mia' => 2, 'yyz' => 2, 'yul' => 2,
            'cle' => 4, 'ord' => 4, 'dfw' => 4, 'den' => 4, 'qro' => 4,
            'sfo' => 3, 'sjc' => 3, 'lax' => 3, 'pdx' => 3, 'sea' => 3, 'phx' => 3,
            'gru' => 5, 'gig' => 5, 'scl' => 5, 'eze' => 5, 'bog' => 5,
            'lhr' => 6, 'cdg' => 6, 'fra' => 6, 'ams' => 6, 'dub' => 6, 'mad' => 6,
            'waw' => 6, 'otp' => 6, 'zrh' => 6,
            'arn' => 7, 'osl' => 7, 'hel' => 7, 'cph' => 7,
            'nrt' => 8, 'hnd' => 8, 'kix' => 8, 'icn' => 8, 'hkg' => 8, 'sin' => 8, 'bkk' => 8,
            'bom' => 9, 'maa' => 9, 'del' => 9, 'syd' => 12, 'mel' => 12, 'akl' => 12,
            'dxb' => 10, 'bah' => 10, 'tlv' => 10, 'jnb' => 11, 'cpt' => 11, 'los' => 11,
        ];
        return is_string($airport) && isset($codes[$airport]) ? $codes[$airport] : 1;
    }
}