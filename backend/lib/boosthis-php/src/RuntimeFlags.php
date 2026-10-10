<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * Environment flags. Every one of these is read fresh (no caching beyond the
 * request) so an operator can flip a flag without a deploy.
 *
 * BOOSTHIS_DISABLED is absolute: no network, no state writes, no meters, no
 * bubble. It outranks every other setting including an active entitlement.
 */
final class RuntimeFlags
{
    /** @var callable(string):(?string)|null */
    private static $envReaderForTests = null;

    private function __construct()
    {
    }

    public static function env(string $name): ?string
    {
        $v = self::$envReaderForTests !== null
            ? (self::$envReaderForTests)($name)
            : getenv($name);
        if ($v === false || $v === '') {
            $v = $_ENV[$name] ?? $_SERVER[$name] ?? null;
        }
        if ($v === null || $v === '' || $v === false) {
            return null;
        }

        return (string) $v;
    }

    private static function flag(string $name): bool
    {
        $v = self::env($name);
        if ($v === null) {
            return false;
        }
        $v = strtolower(trim($v));

        return $v === '1' || $v === 'true' || $v === 'yes' || $v === 'on';
    }

    /** @param callable(string):(?string)|null $reader */
    public static function setEnvReaderForTests(?callable $reader): void
    {
        self::$envReaderForTests = $reader;
    }

    /** Absolute off switch — no network, no state, no meters. */
    public static function disabled(): bool
    {
        return self::flag('BOOSTHIS_DISABLED');
    }

    /** Extra logging to the host error log. Off by default. */
    public static function debug(): bool
    {
        return self::flag('BOOSTHIS_DEBUG');
    }

    /** Opt-in dependency inventory in the snapshot. Off by default. */
    public static function depInventory(): bool
    {
        return self::flag('BOOSTHIS_DEP_INVENTORY');
    }

    /** Project key (also accepted under its legacy name). */
    public static function projectKey(): ?string
    {
        return self::env('BOOSTHIS_PROJECT_KEY') ?? self::env('BOOSTHIS_INVITE_KEY');
    }

    public static function appName(): ?string
    {
        return self::env('BOOSTHIS_APP_NAME');
    }

    public static function installId(): ?string
    {
        return self::env('BOOSTHIS_INSTALL_ID') ?? self::env('BOOSTEN_INSTALL_ID');
    }

    /** Override the API base. Defaults to the hosted service. */
    public static function apiBase(): string
    {
        $v = self::env('BOOSTHIS_API_BASE');
        if ($v === null) {
            return 'https://www.boosthis.com/api';
        }

        return rtrim($v, '/');
    }

    /**
     * Where cross-request state lives. Defaults to a per-project directory
     * under the system temp dir — never a machine-wide path, or two services on
     * one host blend their meters together.
     */
    public static function stateDir(): ?string
    {
        return self::env('BOOSTHIS_STATE_DIR');
    }

    /** Legacy explicit-on alias. */
    public static function forceBubble(): bool
    {
        return self::flag('BOOSTHIS_FORCE_BUBBLE');
    }

    /** Legacy explicit-off alias. */
    public static function noBubble(): bool
    {
        return self::flag('BOOSTHIS_NO_BUBBLE');
    }

    /** Delegate the old public entry point to the single visibility resolver. */
    public static function bubbleEnabled(): bool
    {
        return Bubble::resolveVisibility();
    }
}
