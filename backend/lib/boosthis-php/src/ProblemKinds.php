<?php

declare(strict_types=1);

namespace Boosthis;

final class ProblemKinds
{
    private const KINDS = [
        'ai-cache-cold',
        'ai-duplicate-prompt',
        'ai-no-timeout',
        'ai-retry-no-backoff',
        'ai-serial-calls',
        'ai-stream-usage-missing',
        'api-thundering-herd',
        'budget-regression',
        'cache-headers-missing',
        'cache-never-store',
        'cache-no-revalidator',
        'connection-leak',
        'connection-stalled',
        'eager-list-mount',
        'failing-api',
        'ghost-mount',
        'idle-burn',
        'post-commit-effect-storm',
        'rage-tap',
        'reconnect-per-screen',
        'reconnect-storm',
        'regression',
        'remount-storm-global',
        'render-monolith',
        'render-storm',
        'retry-storm',
        'slow-api',
        'slow-nav',
        'slow-press-handler',
        'slow-route',
        'stranded-interval',
    ];

    private function __construct()
    {
    }

    public static function contains(string $kind): bool
    {
        return in_array($kind, self::KINDS, true);
    }
}