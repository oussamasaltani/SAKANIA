<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * WHERE THIS PROJECT RUNS — recognition, not guesswork.
 *
 * Every hosting platform publishes something about itself to the code running
 * on it, and most publish a region and an environment with it. This module
 * reads only those declarations and turns them into three words from three
 * fixed lists.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO
 * ----------------------------------
 *   - It reads no other configuration and sends none. The markers below are
 *     the only variables it looks at, and it sends the WORD it recognised,
 *     never the value it read. Nothing free-form leaves the process.
 *   - It never guesses a host from a similar one. Something unfamiliar
 *     declaring itself answers "unrecognised", which is a real answer, and an
 *     honest one.
 *   - It never reads an environment as "production" on a hunch. A preview's
 *     numbers being mistaken for the real app's is precisely what this exists
 *     to prevent, so a host that declares nothing answers "unknown".
 *
 * Verbatim port of lib/boosthis-runtime-node/src/hosting.ts. KEEP IN LOCKSTEP
 * with the closed lists in artifacts/api-server/src/lib/hostingPlatforms.ts —
 * the server checks every arriving word against them and stores anything it
 * does not hold as "unrecognised". A guard test on the server side fails if
 * the lists drift.
 */
final class Hosting
{
    /**
     * Kept inside the kit rather than fetched from Boosthis because this answer
     * still has to render when the status page exists precisely because the
     * service cannot be reached.
     *
     * @var array<string,string>
     */
    public const HOSTING_LABELS = [
        'vercel' => 'Vercel Functions',
        'aws-lambda' => 'AWS Lambda',
        'netlify' => 'Netlify Functions',
        'gcp-functions' => 'Google Cloud Functions',
        'azure-functions' => 'Azure Functions',
        'cloud-run' => 'Google Cloud Run',
        'azure-app-service' => 'Azure App Service',
        'aws-ecs' => 'AWS ECS',
        'fly-io' => 'Fly.io',
        'render' => 'Render',
        'railway' => 'Railway',
        'heroku' => 'Heroku',
        'replit' => 'Replit',
        'koyeb' => 'Koyeb',
        'kubernetes' => 'Kubernetes',
        'unrecognised' => 'Unrecognised host',
        'undeclared' => 'No hosting platform declared',
    ];

    /** @var array<string,string> */
    public const ENVIRONMENT_LABELS = [
        'production' => 'Production',
        'preview' => 'Preview',
        'development' => 'Development',
        'unknown' => 'Environment not declared',
    ];

    /** @var array{platform:string,region:string,environment:string}|null */
    private static ?array $cached = null;

    private function __construct()
    {
    }

    /**
     * Read one environment variable. Never throws — an unusual host-provided
     * environment must not take a customer's app down.
     */
    private static function env(string $name): string
    {
        try {
            $value = getenv($name);

            return is_string($value) ? trim($value) : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /** @return array{platform:string,region:string,environment:string} */
    private static function nothingDeclared(): array
    {
        // Nothing published a host: a laptop, a plain virtual machine, a server
        // somebody owns. A real answer, not a blank.
        return [
            'platform' => 'undeclared',
            'region' => 'undeclared',
            'environment' => 'unknown',
        ];
    }

    /**
     * Vercel's own word for the deployment, straight through — production /
     * preview / development are exactly the three it publishes.
     */
    private static function vercelEnvironment(): string
    {
        $value = strtolower(self::env('VERCEL_ENV'));

        return in_array($value, ['production', 'preview', 'development'], true)
            ? $value
            : 'unknown';
    }

    /**
     * Netlify publishes a build CONTEXT. Deploy previews and branch deploys
     * are both previews; its "dev" is a local run, which is development, not a
     * preview — mapping it to "preview" would be the guess this avoids.
     */
    private static function netlifyEnvironment(): string
    {
        $context = strtolower(self::env('CONTEXT'));
        if ($context === 'production') {
            return 'production';
        }
        if ($context === 'deploy-preview' || $context === 'branch-deploy') {
            return 'preview';
        }
        if ($context === 'dev') {
            return 'development';
        }

        return 'unknown';
    }

    /**
     * Recognise the platform, its region and its environment from what the
     * platform publishes about itself. Pure, cheap, and never throws.
     *
     * ORDER MATTERS. Several of these hosts run ON another one and set its
     * markers too: Vercel's PHP functions run on Lambda, Netlify's do too, and
     * Google's Cloud Functions run on the same container service as Cloud Run.
     * The more specific host is tested first every time, or a Vercel project
     * would file itself as raw AWS.
     *
     * @return array{platform:string,region:string,environment:string}
     */
    public static function detect(): array
    {
        try {
            // ── Function hosts ──

            // Vercel before Lambda: its functions carry the Lambda markers as well.
            if (self::env('VERCEL') === '1' || self::env('VERCEL_ENV') !== '') {
                return [
                    'platform' => 'vercel',
                    'region' => self::env('VERCEL_REGION') ?: 'undeclared',
                    'environment' => self::vercelEnvironment(),
                ];
            }

            // Netlify before Lambda, for the same reason.
            if (
                self::env('NETLIFY') === 'true'
                || self::env('NETLIFY_LOCAL') !== ''
                || self::env('SITE_ID') !== ''
            ) {
                return [
                    'platform' => 'netlify',
                    'region' => self::env('AWS_REGION') ?: 'undeclared',
                    'environment' => self::netlifyEnvironment(),
                ];
            }

            if (self::env('AWS_LAMBDA_FUNCTION_NAME') !== '') {
                return [
                    'platform' => 'aws-lambda',
                    'region' => self::env('AWS_REGION') ?: (self::env('AWS_DEFAULT_REGION') ?: 'undeclared'),
                    // Lambda has no environment concept of its own — a stage
                    // lives in the function name, which we neither read nor
                    // send. Calling every Lambda "production" would be a guess.
                    'environment' => 'unknown',
                ];
            }

            // Google Cloud Functions before Cloud Run: a function sets BOTH
            // the function marker and Cloud Run's service marker.
            if (self::env('FUNCTION_TARGET') !== '') {
                return [
                    'platform' => 'gcp-functions',
                    'region' => self::env('FUNCTION_REGION') ?: 'undeclared',
                    'environment' => 'unknown',
                ];
            }

            // Azure Functions before App Service, same overlap.
            if (self::env('FUNCTIONS_WORKER_RUNTIME') !== '') {
                return [
                    'platform' => 'azure-functions',
                    'region' => self::env('REGION_NAME') ?: 'undeclared',
                    'environment' => 'unknown',
                ];
            }

            // ── Long-running container and application hosts ──

            if (self::env('K_SERVICE') !== '') {
                return [
                    'platform' => 'cloud-run',
                    // Cloud Run publishes its region only through its metadata
                    // service, which is a network call this must never make.
                    'region' => 'undeclared',
                    'environment' => 'unknown',
                ];
            }

            if (self::env('WEBSITE_SITE_NAME') !== '') {
                return [
                    'platform' => 'azure-app-service',
                    'region' => self::env('REGION_NAME') ?: 'undeclared',
                    'environment' => 'unknown',
                ];
            }

            if (self::env('FLY_APP_NAME') !== '') {
                return [
                    'platform' => 'fly-io',
                    'region' => self::env('FLY_REGION') ?: 'undeclared',
                    'environment' => 'unknown',
                ];
            }

            if (self::env('RENDER') === 'true' || self::env('RENDER_SERVICE_ID') !== '') {
                return [
                    'platform' => 'render',
                    'region' => 'undeclared',
                    // Render's own marker for a pull-request preview service.
                    // Absent means an ordinary service, which Render only ever
                    // runs as production.
                    'environment' => self::env('IS_PULL_REQUEST') === 'true' ? 'preview' : 'production',
                ];
            }

            if (self::env('RAILWAY_ENVIRONMENT') !== '' || self::env('RAILWAY_PROJECT_ID') !== '') {
                // Railway lets a project have any number of named environments
                // and calls the live one "production". Every other name is one
                // of the throwaway environments people spin up per branch, so
                // it reads as a preview — never silently as production.
                $named = strtolower(
                    self::env('RAILWAY_ENVIRONMENT_NAME') ?: self::env('RAILWAY_ENVIRONMENT')
                );
                return [
                    'platform' => 'railway',
                    'region' => self::env('RAILWAY_REPLICA_REGION') ?: 'undeclared',
                    'environment' => $named === ''
                        ? 'unknown'
                        : ($named === 'production' ? 'production' : 'preview'),
                ];
            }

            if (self::env('KOYEB_APP_NAME') !== '') {
                return [
                    'platform' => 'koyeb',
                    'region' => self::env('KOYEB_REGION') ?: 'undeclared',
                    'environment' => 'unknown',
                ];
            }

            if (self::env('REPL_ID') !== '' || self::env('REPLIT_DEPLOYMENT') !== '') {
                return [
                    'platform' => 'replit',
                    'region' => 'undeclared',
                    // Replit publishes exactly this distinction: a published
                    // app sets the marker, the workspace copy does not. The
                    // workspace copy is development, not a preview of one.
                    'environment' => self::env('REPLIT_DEPLOYMENT') === '1'
                        ? 'production'
                        : 'development',
                ];
            }

            // ECS before Heroku and Kubernetes: a container on Fargate can
            // carry a scheduler's markers too, and its own is the specific one.
            if (
                self::env('ECS_CONTAINER_METADATA_URI_V4') !== ''
                || self::env('ECS_CONTAINER_METADATA_URI') !== ''
            ) {
                return [
                    'platform' => 'aws-ecs',
                    'region' => self::env('AWS_REGION') ?: (self::env('AWS_DEFAULT_REGION') ?: 'undeclared'),
                    'environment' => 'unknown',
                ];
            }

            if (self::env('DYNO') !== '') {
                return [
                    'platform' => 'heroku',
                    'region' => 'undeclared',
                    'environment' => 'unknown',
                ];
            }

            // Last of the recognised hosts on purpose. Kubernetes is
            // underneath a great many of the platforms above, so it is only
            // the answer once none of them has claimed the process.
            if (self::env('KUBERNETES_SERVICE_HOST') !== '') {
                return [
                    'platform' => 'kubernetes',
                    'region' => 'undeclared',
                    'environment' => 'unknown',
                ];
            }

            return self::nothingDeclared();
        } catch (\Throwable) {
            // A project's readings must never be lost over a question about
            // where it runs. Answer "we could not tell" and carry on.
            return self::nothingDeclared();
        }
    }

    /**
     * The detection, read once per process.
     *
     * Caching is safe and correct here: a process cannot move between
     * platforms while it is running. A project that MOVES gets a new process,
     * which detects afresh and re-registers, exactly how the record refreshes.
     *
     * @return array{platform:string,region:string,environment:string}
     */
    public static function facts(): array
    {
        if (self::$cached === null) {
            self::$cached = self::detect();
        }

        return self::$cached;
    }

    /**
     * "Vercel Functions · Preview · iad1" — one line for the kit's status page.
     *
     * The environment appears only when it says something, so a preview stands
     * out instead of being lost in a row of "not declared" on every healthy
     * install.
     *
     * @param array{platform:string,region:string,environment:string}|null $facts
     */
    public static function line(?array $facts = null): string
    {
        $facts = $facts ?? self::facts();
        $platform = self::HOSTING_LABELS[$facts['platform']]
            ?? self::HOSTING_LABELS['unrecognised'];
        $parts = [$platform];
        $environment = $facts['environment'];
        if ($environment === 'preview' || $environment === 'development') {
            $parts[] = self::ENVIRONMENT_LABELS[$environment];
        }
        if ($facts['region'] !== '' && $facts['region'] !== 'undeclared') {
            $parts[] = $facts['region'];
        }

        return implode(' · ', $parts);
    }

    /** Test seam. Never called in production. */
    public static function resetForTests(): void
    {
        self::$cached = null;
    }
}