<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * The server-authoritative registration verdict shared by every display
 * surface. Pending and unreachable are both public "unknown" outcomes.
 */
final class Registration
{
    private const RECHECK_MS = 30000;
    private const TIMEOUT_MS = 8000;

    private static string $state = 'pending';
    private static bool $inFlight = false;
    private static int $lastAttemptAt = 0;

    private function __construct()
    {
    }

    public static function markRegistrationConfirmed(): void
    {
        self::$state = 'registered';
    }

    public static function markRegistrationRefused(): void
    {
        self::$state = 'unregistered';
    }

    public static function markRegistrationUnreachable(): void
    {
        if (self::$state === 'registered' || self::$state === 'unregistered') {
            return;
        }
        self::$state = 'unreachable';
    }

    public static function state(): string
    {
        return self::$state;
    }

    public static function verdict(): string
    {
        if (self::$state === 'registered') {
            return 'registered';
        }
        if (self::$state === 'unregistered') {
            return 'unregistered';
        }

        return 'unknown';
    }

    /** Ask once, bounded and self-throttled. Never throws into the host. */
    public static function checkOnce(
        ?string $endpoint,
        ?string $installId,
        ?string $projectKey,
        ?int $nowMs = null
    ): void {
        try {
            if (self::$state === 'registered' || self::$state === 'unregistered' || self::$inFlight) {
                return;
            }
            $now = $nowMs ?? Telemetry::nowMs();
            if (self::$lastAttemptAt !== 0 && ($now - self::$lastAttemptAt) < self::RECHECK_MS) {
                return;
            }
            if ($endpoint === null || $endpoint === '' || $installId === null || $installId === '') {
                self::markRegistrationRefused();
                return;
            }
            if ($projectKey === null || $projectKey === '') {
                self::markRegistrationRefused();
                return;
            }

            self::$inFlight = true;
            self::$lastAttemptAt = $now;
            $url = rtrim($endpoint, '/') . '/installs/' . rawurlencode($installId) . '/registration';
            $res = Transmit::safeGet($url, [], $projectKey, self::TIMEOUT_MS);
            $status = (int) ($res['status'] ?? 0);
            $body = $res['body'] ?? null;
            if ($status === 200 && is_array($body) && ($body['registered'] ?? null) === true) {
                self::markRegistrationConfirmed();
            } elseif ($status === 200 && is_array($body) && ($body['registered'] ?? null) === false) {
                self::markRegistrationRefused();
            } elseif ($status === 401 || $status === 403) {
                self::markRegistrationRefused();
            } else {
                self::markRegistrationUnreachable();
            }
        } catch (\Throwable $e) {
            self::markRegistrationUnreachable();
        } finally {
            self::$inFlight = false;
        }
    }

    public static function resetForTests(): void
    {
        self::$state = 'pending';
        self::$inFlight = false;
        self::$lastAttemptAt = 0;
    }
}