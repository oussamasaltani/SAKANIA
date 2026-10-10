<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * Resolves the one project key this PHP runtime uses.
 *
 * PHP has no existing programmatic host override and reads no Boosthis config
 * file, so the applicable precedence is: PHP environment, key passed in code,
 * shared environment, legacy shared environment.
 */
final class ProjectKey
{
    public const SOURCE_ENV_RUNTIME = 'env-runtime';
    public const SOURCE_CODE = 'code';
    public const SOURCE_ENV_SHARED = 'env-shared';
    public const SOURCE_ENV_LEGACY = 'env-legacy';
    public const SOURCE_NONE = 'none';

    private function __construct()
    {
    }

    /**
     * @return array{key:?string,source:string,overrodeShared:bool,display:?string,noKeyChosen:bool}
     */
    public static function resolve(?string $explicit = null): array
    {
        $runtime = self::accepted(RuntimeFlags::env('BOOSTHIS_PROJECT_KEY_PHP'), 'BOOSTHIS_PROJECT_KEY_PHP');
        $code = self::accepted($explicit, 'the key passed in code');
        $shared = self::accepted(RuntimeFlags::env('BOOSTHIS_PROJECT_KEY'), 'BOOSTHIS_PROJECT_KEY');
        $legacy = self::accepted(RuntimeFlags::env('BOOSTHIS_INVITE_KEY'), 'BOOSTHIS_INVITE_KEY');

        if ($runtime !== null) {
            $replaced = $code ?? $shared ?? $legacy;

            return self::result(
                $runtime,
                self::SOURCE_ENV_RUNTIME,
                $replaced !== null && $replaced !== $runtime
            );
        }
        if ($code !== null) {
            return self::result($code, self::SOURCE_CODE, false);
        }
        if ($shared !== null) {
            return self::result($shared, self::SOURCE_ENV_SHARED, false);
        }
        if ($legacy !== null) {
            return self::result($legacy, self::SOURCE_ENV_LEGACY, false);
        }

        $switch = strtolower(trim((string) RuntimeFlags::env('BOOSTHIS_NO_PROJECT_KEY')));
        return ['key' => null, 'source' => self::SOURCE_NONE, 'overrodeShared' => false, 'display' => null, 'noKeyChosen' => in_array($switch, ['1', 'true', 'yes', 'on'], true)];
    }

    public static function mask(?string $key): ?string
    {
        $key = self::clean($key);
        if ($key === null) {
            return null;
        }

        // Count Unicode characters, not UTF-8 bytes: a byte slice can split a
        // character and put malformed text into a log or status surface.
        try {
            $count = preg_match_all('/./us', $key, $characters);
            if ($count === false || !isset($characters[0]) || !is_array($characters[0])) {
                return 'set';
            }

            return $count >= 8
                ? "\u{2026}" . implode('', array_slice($characters[0], -4))
                : 'set';
        } catch (\Throwable) {
            return 'set';
        }
    }

    /** @param array{key:?string,source:string,overrodeShared:bool,display:?string} $resolved */
    public static function describe(array $resolved): string
    {
        return match ($resolved['source'] ?? self::SOURCE_NONE) {
            self::SOURCE_ENV_RUNTIME => 'PHP key from the environment',
            self::SOURCE_CODE => 'key passed in code',
            self::SOURCE_ENV_SHARED => 'shared key from BOOSTHIS_PROJECT_KEY',
            self::SOURCE_ENV_LEGACY => 'shared key from BOOSTHIS_INVITE_KEY',
            default => 'no project key configured',
        };
    }

    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function accepted(?string $value, string $source): ?string
    {
        $cleaned = self::clean($value);
        return $cleaned !== null && StartAnnounce::warnProjectKeyRefused($cleaned, $source) ? null : $cleaned;
    }

    /** @return array{key:string,source:string,overrodeShared:bool,display:string,noKeyChosen:bool} */
    private static function result(string $key, string $source, bool $overrode): array
    {
        return [
            'key' => $key,
            'source' => $source,
            'overrodeShared' => $overrode,
            'display' => self::mask($key) ?? 'set',
            'noKeyChosen' => false,
        ];
    }
}