<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * Which dashboard project this kit reports to, in the developer's own words.
 *
 * Only the server can resolve a project key to its name and short code, so the
 * consent reply supplies both. The name is developer-authored display data: it
 * is screened here before any surface can read it, persisted beside the
 * install's credentials, and rendered only through display().
 */
final class ProjectIdentity
{
    public const PROJECT_LABEL = 'Project';
    public const PROJECT_UNNAMED_TEXT = 'Unnamed project';
    public const PROJECT_UNKNOWN_TEXT = 'Not received from Boosthis yet';
    public const INSTALL_ID_LABEL = 'Install ID';
    public const INSTALL_ID_UNKNOWN_TEXT = 'not assigned yet';
    public const SCORE_CAPTION = 'Measured on this device. Not proof anything reached Boosthis.';

    /** @var array{name:?string,code:?string} */
    private static array $current = ['name' => null, 'code' => null];
    private static ?string $loadedForInstallId = null;

    private function __construct()
    {
    }

    /** Strip controls, collapse whitespace, trim, then cap at 60 characters. */
    public static function sanitizeName(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $cleaned = preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}]+/u', ' ', $value);
        if (!is_string($cleaned)) {
            return null;
        }
        $cleaned = preg_replace('/\s+/u', ' ', $cleaned);
        if (!is_string($cleaned)) {
            return null;
        }
        $cleaned = trim($cleaned);
        if ($cleaned === '') {
            return null;
        }
        if (preg_match_all('/./us', $cleaned, $chars) === false) {
            return null;
        }
        $cleaned = implode('', array_slice($chars[0], 0, 60));
        $cleaned = trim($cleaned);

        return $cleaned !== '' ? $cleaned : null;
    }

    /** Codes are server-owned, but still restricted to their known safe shape. */
    public static function sanitizeCode(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $cleaned = substr((string) preg_replace('/[^A-Za-z0-9]/', '', $value), 0, 16);

        return $cleaned !== '' ? $cleaned : null;
    }

    /**
     * The one spelling used by every surface.
     *
     * @param array{name:?string,code:?string}|null $project
     */
    public static function display(?array $project): string
    {
        return (string) Safe::run(static function () use ($project): string {
            $code = self::sanitizeCode($project['code'] ?? null);
            if ($code === null) {
                return self::PROJECT_UNKNOWN_TEXT;
            }
            $name = self::sanitizeName($project['name'] ?? null)
                ?? self::PROJECT_UNNAMED_TEXT;

            return $name . ' (' . $code . ')';
        }, self::PROJECT_UNKNOWN_TEXT);
    }

    /** Restore the project record stored for this install. Best-effort. */
    public static function restore(?string $installId): void
    {
        Safe::fire(static function () use ($installId): void {
            if (!is_string($installId) || $installId === '') {
                return;
            }
            if (self::$loadedForInstallId === $installId) {
                return;
            }
            self::$loadedForInstallId = $installId;
            self::$current = ['name' => null, 'code' => null];
            $stored = Store::get(self::storeKey($installId), null);
            if (!is_array($stored)) {
                return;
            }
            $name = self::sanitizeName($stored['name'] ?? null);
            $code = self::sanitizeCode($stored['code'] ?? null);
            if ($name !== null || $code !== null) {
                self::$current = ['name' => $name, 'code' => $code];
            }
        });
    }

    /**
     * Record project fields from a successful consent reply. Older servers omit
     * both, in which case the last known answer is deliberately left untouched.
     *
     * @param array<mixed> $body
     */
    public static function recordConsent(string $installId, array $body): void
    {
        Safe::fire(static function () use ($installId, $body): void {
            if (($body['projectName'] ?? null) === null && ($body['projectCode'] ?? null) === null) {
                return;
            }
            self::restore($installId);
            $code = self::sanitizeCode($body['projectCode'] ?? null);
            $name = self::sanitizeName($body['projectName'] ?? null);
            if ($code !== null) {
                self::$current = ['name' => $name, 'code' => $code];
            } elseif ($name !== null) {
                self::$current['name'] = $name;
            }
            Store::set(self::storeKey($installId), [
                'name' => self::$current['name'],
                'code' => self::$current['code'],
            ]);
        });
    }

    /** The already-rendered project identity for panel and diagnostic reads. */
    public static function displayCurrent(): string
    {
        return (string) Safe::run(static function (): string {
            self::restore(Telemetry::installId());

            return self::display(self::$current);
        }, self::PROJECT_UNKNOWN_TEXT);
    }

    private static function storeKey(string $installId): string
    {
        return 'project:' . $installId;
    }

    /** Test seam: emulate a fresh PHP process while preserving the Store. */
    public static function resetForTests(): void
    {
        self::$current = ['name' => null, 'code' => null];
        self::$loadedForInstallId = null;
    }
}