<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * The community rule cache.
 *
 * The checklist ships with the kit, but real projects keep finding patterns the
 * shipped rules do not cover. The server serves those learned rules from
 * GET /rules/community, and this class keeps a local copy so the checklist and
 * the MCP tools work at full strength without a network round trip on every
 * call.
 *
 * FAILS OPEN, ALWAYS. A cold cache, an unreachable server, a bad key, a
 * malformed answer — every one of them means "carry on with the shipped rules".
 * The community layer can only ever ADD to what the kit already knows.
 */
final class Community
{
    /** A cached copy is good for six hours. */
    public const CACHE_TTL_MS = 21600000;
    public const TIMEOUT_MS = 5000;
    /** Refuse an oversized answer rather than parsing it. */
    public const MAX_BYTES = 1000000;

    private const KEY = 'community_rules';

    private static bool $refreshDisabled = false;

    private function __construct()
    {
    }

    public static function endpointBase(): string
    {
        return RuntimeFlags::apiBase();
    }

    /**
     * The cached rules, or an empty list when nothing is cached yet.
     *
     * @return list<array<string,mixed>>
     */
    public static function readCommunityCache(): array
    {
        $rec = Store::get(self::KEY, []);
        if (!is_array($rec)) {
            return [];
        }
        $rules = $rec['rules'] ?? null;

        return is_array($rules) ? array_values($rules) : [];
    }

    public static function cacheAgeMs(): ?int
    {
        $rec = Store::get(self::KEY, []);
        $at = is_array($rec) ? ($rec['fetchedAt'] ?? null) : null;

        return is_int($at) ? max(0, Telemetry::nowMs() - $at) : null;
    }

    public static function isStale(): bool
    {
        $age = self::cacheAgeMs();

        return $age === null || $age >= self::CACHE_TTL_MS;
    }

    /**
     * Refresh the cache when it has aged out. Cheap and safe to call from the
     * request path: it returns immediately unless a refresh is genuinely due.
     */
    public static function refreshCommunityCacheIfStale(): void
    {
        if (self::$refreshDisabled || RuntimeFlags::disabled()) {
            return;
        }
        if (!self::isStale()) {
            return;
        }
        Safe::fire(static function (): void {
            // Stamp the attempt BEFORE the call so a server that is down cannot
            // turn every request into a retry.
            Store::update(self::KEY, static function ($cur) {
                $rec = is_array($cur) ? $cur : [];
                $rec['attemptedAt'] = Telemetry::nowMs();

                return $rec;
            }, []);

            $res = Transmit::safeGet(
                self::endpointBase() . '/rules/community',
                ['runtime' => Telemetry::RUNTIME],
                Telemetry::projectKey(),
                self::TIMEOUT_MS
            );
            if ((int) $res['status'] !== 200 || !is_array($res['body'])) {
                return;
            }
            $rules = $res['body']['rules'] ?? null;
            if (!is_array($rules)) {
                return;
            }
            $encoded = Json::encode($rules);
            if ($encoded === null || strlen($encoded) > self::MAX_BYTES) {
                return;
            }
            Store::set(self::KEY, [
                'rules' => array_values($rules),
                'fetchedAt' => Telemetry::nowMs(),
            ]);
        });
    }

    /** Test seam — keeps the suite off the network. */
    public static function setRefreshDisabledForTesting(bool $disabled): void
    {
        self::$refreshDisabled = $disabled;
    }

    public static function clear(): void
    {
        Store::delete(self::KEY);
    }
}
