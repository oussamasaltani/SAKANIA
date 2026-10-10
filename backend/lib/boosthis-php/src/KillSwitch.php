<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * The entitlement vault — gate 1, and the only gate that can turn the whole kit
 * off from the server side.
 *
 * DEFAULT CLOSED. A project that has never had a successful `active` answer
 * measures nothing, uploads nothing and shows nothing. That is deliberate: an
 * install that cannot reach the server on its very first run must not quietly
 * behave like a paid one.
 *
 * Once a project HAS been activated, an offline patch is not punished: the last
 * `active` answer stays good for the grace window the server handed back. Any
 * non-active answer is sticky immediately — revoked, unpaid, tampered and
 * paused all take effect on receipt, with no grace.
 *
 * The recovery request itself is never gated (only BOOSTHIS_DISABLED stops it),
 * or an install could never come back from `revoked`.
 */
final class KillSwitch
{
    private const KEY = 'entitlement';
    /** How often a healthy install re-checks. */
    public const CHECK_INTERVAL_MS = 21600000; // 6 hours
    /** Fallback when the server does not state a grace window. */
    public const DEFAULT_GRACE_SECONDS = 604800; // 7 days

    public const ACTIVE = 'active';
    public const REVOKED = 'revoked';
    public const UNPAID = 'unpaid';
    public const TAMPERED = 'tampered';
    public const PAUSED = 'paused';
    /** Never activated, or never answered — not a server status. */
    public const HIDDEN = 'hidden';

    /**
     * Running, but no check-in has ever succeeded. Deliberately NOT
     * {@see self::HIDDEN}: the bubble DRAWS and the panel renders its own
     * "Not registered yet" notice. Nothing is measured or uploaded in this
     * state. See docs/kit-bubble-draw-contract.md.
     */
    public const UNREGISTERED = 'unregistered';

    private function __construct()
    {
    }

    /** @return array<string,mixed> */
    public static function state(): array
    {
        $v = Store::get(self::KEY, []);

        return is_array($v) ? $v : [];
    }

    /**
     * Is the kit allowed to run right now? Everything in the kit asks this
     * before doing anything visible or outbound.
     */
    public static function isActive(): bool
    {
        if (RuntimeFlags::disabled()) {
            return false;
        }
        $s = self::state();
        $status = $s['status'] ?? null;
        if ($status !== self::ACTIVE) {
            return false;
        }
        $lastSuccess = $s['lastSuccessAt'] ?? null;
        if (!is_int($lastSuccess)) {
            return false;
        }
        $grace = (int) ($s['graceSeconds'] ?? self::DEFAULT_GRACE_SECONDS);
        if ($grace < 0) {
            $grace = self::DEFAULT_GRACE_SECONDS;
        }

        return (Telemetry::nowMs() - $lastSuccess) <= ($grace * 1000);
    }

    /**
     * True only while the first authoritative entitlement answer is pending.
     * A named server status, including a lapsed prior activation, is a decision
     * rather than a delay and must not retain measurements.
     */
    public static function awaitingFirstAnswer(): bool
    {
        return !is_string(self::state()['status'] ?? null);
    }

    /**
     * What to show a developer: `unregistered` for a project that has never
     * checked in (the bubble draws and says so), `hidden` only for the states
     * with nothing readable — a lapsed offline grace window here, plus the env
     * kill switch and a tampered install resolved by the caller — otherwise the
     * server's own word so the bubble can explain itself.
     */
    public static function visibleStatus(): string
    {
        $s = self::state();
        $status = $s['status'] ?? null;
        if (!is_string($status)) {
            return self::UNREGISTERED;
        }
        if ($status === self::ACTIVE && !self::isActive()) {
            // Activated once, then offline past the grace window.
            return self::HIDDEN;
        }

        return $status;
    }

    public static function message(): ?string
    {
        $m = self::state()['message'] ?? null;

        return is_string($m) && $m !== '' ? $m : null;
    }

    /** True when a fresh check is due (or none has ever succeeded). */
    public static function checkDue(): bool
    {
        $s = self::state();
        $at = $s['checkedAt'] ?? null;
        if (!is_int($at)) {
            return true;
        }

        return (Telemetry::nowMs() - $at) >= self::CHECK_INTERVAL_MS;
    }

    /**
     * POST /entitlements/check. Returns the status string on success, null when
     * the server could not be reached (which leaves the stored answer alone —
     * an unreachable server is not a revocation).
     *
     * @param array{status:string,manifestHash:string|null}|null $integrity
     */
    public static function check(
        ?array $integrity = null,
        bool $force = false,
        int $timeoutMs = 4000
    ): ?string
    {
        if (RuntimeFlags::disabled()) {
            return null;
        }
        if (!$force && !self::checkDue()) {
            return is_string(self::state()['status'] ?? null)
                ? (string) self::state()['status']
                : null;
        }

        return Safe::run(static function () use ($integrity, $timeoutMs): ?string {
            $installId = Telemetry::installId();
            // Delete token FIRST, matching every sibling kit: the server only
            // refreshes lastEntitlementCheckAt / packageVersion for a write-capable
            // credential, so a read-token check-in leaves this install's "last
            // contact" and kit version permanently stale on the dashboard.
            $token = Telemetry::deleteToken() ?? Telemetry::readToken();
            if ($installId === null || $token === null) {
                return null;
            }
            $payload = [
                'installId' => $installId,
                'kitVersion' => Thresholds::RUNTIME_VERSION,
            ];
            if ($integrity !== null) {
                $payload['integrity'] = $integrity;
            }
            $res = Transmit::safeTransmit(
                RuntimeFlags::apiBase() . '/entitlements/check',
                $payload,
                $token,
                max(1, $timeoutMs)
            );
            $status = (int) $res['status'];
            $body = is_array($res['body']) ? $res['body'] : null;
            if ($status === 401
                && $body !== null
                && ($body['error'] ?? null) === 'credentials_cut'
            ) {
                $reason = is_string($body['reason'] ?? null) ? (string) $body['reason'] : self::REVOKED;
                if (!in_array($reason, [self::REVOKED, self::UNPAID, self::TAMPERED, self::PAUSED], true)) {
                    $reason = self::REVOKED;
                }
                $detail = is_string($body['detail'] ?? null) ? (string) $body['detail'] : null;
                self::store($reason, $detail, self::DEFAULT_GRACE_SECONDS, false);

                return $reason;
            }
            if ($status !== 200 || $body === null) {
                return null;
            }
            $answer = is_string($body['status'] ?? null) ? (string) $body['status'] : null;
            if ($answer === null) {
                return null;
            }
            $grace = is_int($body['graceSeconds'] ?? null)
                ? (int) $body['graceSeconds']
                : self::DEFAULT_GRACE_SECONDS;
            $message = is_string($body['message'] ?? null) ? (string) $body['message'] : null;
            self::store($answer, $message, $grace, $answer === self::ACTIVE);

            return $answer;
        });
    }

    private static function store(
        string $status,
        ?string $message,
        int $graceSeconds,
        bool $success
    ): void {
        Store::update(self::KEY, static function ($cur) use (
            $status,
            $message,
            $graceSeconds,
            $success
        ) {
            $rec = is_array($cur) ? $cur : [];
            $rec['status'] = $status;
            $rec['message'] = $message;
            $rec['graceSeconds'] = $graceSeconds;
            $rec['checkedAt'] = Telemetry::nowMs();
            if ($success) {
                $rec['lastSuccessAt'] = Telemetry::nowMs();
            }

            return $rec;
        }, []);
    }

    /** Test seam. */
    public static function seedForTests(string $status, int $graceSeconds = 604800): void
    {
        self::store($status, null, $graceSeconds, $status === self::ACTIVE);
    }

    public static function clear(): void
    {
        Store::delete(self::KEY);
    }
}
