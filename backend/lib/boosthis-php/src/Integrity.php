<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * Tamper evidence.
 *
 * Every kit is stamped at the edge with a manifest listing each shipped file
 * and its SHA-256, plus a hash over the manifest itself. This class re-computes
 * those hashes locally and reports what it found to the entitlement check, so a
 * kit whose files were edited after install can be told apart from one that was
 * not.
 *
 * THE SELF-ENTRY RULE: a manifest can never contain its own hash, so the
 * manifest's entry for itself is EXCLUDED from the file walk. Every runtime
 * does it the same way, or the same bytes would hash differently per kit.
 *
 * Honest-or-silent: an unstamped checkout (no manifest on disk) reports NOTHING
 * rather than claiming "ok". Absence of evidence is not evidence of integrity.
 */
final class Integrity
{
    public const MANIFEST_FILE = 'boosthis-manifest.json';
    public const STATUS_OK = 'ok';
    public const STATUS_MISMATCH = 'mismatch';

    /** Never walk more than this many files, whatever the manifest claims. */
    private const MAX_FILES = 400;
    private const KEY = 'integrity';
    /** A full re-hash once a day is plenty; it is disk work, not free. */
    private const RECHECK_MS = 86400000;

    private function __construct()
    {
    }

    /**
     * The integrity block for the entitlement check, or null when this install
     * has no manifest to check against.
     *
     * @return array{status:string,manifestHash:string}|null
     */
    public static function status(): ?array
    {
        return Safe::run(static function (): ?array {
            $cached = Store::get(self::KEY, null);
            if (is_array($cached)
                && is_int($cached['at'] ?? null)
                && (Telemetry::nowMs() - (int) $cached['at']) < self::RECHECK_MS
                && is_string($cached['status'] ?? null)
                && is_string($cached['manifestHash'] ?? null)
            ) {
                return [
                    'status' => (string) $cached['status'],
                    'manifestHash' => (string) $cached['manifestHash'],
                ];
            }

            $computed = self::compute();
            if ($computed === null) {
                return null;
            }
            Store::set(self::KEY, [
                'status' => $computed['status'],
                'manifestHash' => $computed['manifestHash'],
                'at' => Telemetry::nowMs(),
            ]);

            return $computed;
        });
    }

    /**
     * Re-hash the shipped files against the manifest.
     *
     * @return array{status:string,manifestHash:string}|null
     */
    public static function compute(): ?array
    {
        return Safe::run(static function (): ?array {
            $root = self::kitRoot();
            $manifestPath = $root . '/' . self::MANIFEST_FILE;
            if (!is_file($manifestPath)) {
                return null;
            }
            $raw = @file_get_contents($manifestPath);
            if (!is_string($raw) || $raw === '') {
                return null;
            }
            $manifest = Json::decode($raw);
            if (!is_array($manifest)) {
                return null;
            }
            $files = $manifest['files'] ?? null;
            $manifestHash = $manifest['manifest_sha256'] ?? null;
            if (!is_array($files) || !is_string($manifestHash) || $manifestHash === '') {
                return null;
            }

            $status = self::STATUS_OK;
            $seen = 0;
            foreach ($files as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $path = $entry['path'] ?? null;
                $expected = $entry['sha256'] ?? null;
                if (!is_string($path) || !is_string($expected)) {
                    continue;
                }
                // The manifest cannot contain its own hash — skip the
                // self-entry, exactly as the sibling kits do.
                if ($path === self::MANIFEST_FILE) {
                    continue;
                }
                if (++$seen > self::MAX_FILES) {
                    break;
                }
                $full = $root . '/' . ltrim($path, '/');
                if (!is_file($full)) {
                    $status = self::STATUS_MISMATCH;
                    break;
                }
                $actual = @hash_file('sha256', $full);
                if (!is_string($actual) || !hash_equals($expected, $actual)) {
                    $status = self::STATUS_MISMATCH;
                    break;
                }
            }

            return ['status' => $status, 'manifestHash' => $manifestHash];
        });
    }

    /** The kit's own directory — src/ lives one level below it. */
    public static function kitRoot(): string
    {
        return dirname(__DIR__);
    }

    public static function clear(): void
    {
        Store::delete(self::KEY);
    }
}
