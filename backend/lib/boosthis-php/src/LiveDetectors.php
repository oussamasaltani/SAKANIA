<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * Outbound retry-storm / fan-out detector (PHP port of Java's
 * {@code LiveDetectors.java} and Go's {@code live_detectors.go}).
 *
 * Two observe-only detectors that surface outbound anti-patterns the per-route
 * timer cannot see, emitted as ordinary crossCutting findings so they ride the
 * EXISTING snapshot pipeline (no new server route, no new table):
 *
 *   - retry-storm — the same outbound host is hammered many times in a short
 *     window with no backoff (a classic missing-retry-budget bug — the exact
 *     failure mode from the July 2026 registration incident).
 *   - api-thundering-herd — many DISTINCT hosts contacted inside one very short
 *     window (a fan-out burst). Derived from the SAME attempt buffer, so it adds
 *     no new collection.
 *
 * EXPLICIT-API-ONLY, exactly like Go and Java. PHP has no safe zero-patch
 * auto-observe surface for outbound calls — a drop-in kit must never swap the
 * host's HTTP client — so detection is fed ONLY when the host calls
 * {@link recordOutboundAttempt()} (or the kit's own outbound wrapper). Host apps
 * see nothing unless they opt in.
 *
 * PHP-SPECIFIC LIFECYCLE. Java/Go hold the per-host attempt buffer in a
 * long-lived process. PHP has none, so the attempt timestamps live in
 * {@link Store} under an exclusive lock — the same read-modify-write path every
 * counter in this kit uses so concurrent FPM workers do not lose attempts. The
 * windows (10s storm window, 50ms cluster window) are wall-clock, so
 * cross-request persistence is faithful to the siblings.
 *
 * PRIVACY (identical to Java/Go). The finding {@code name} is a bare outbound
 * HOSTNAME (retry-storm) or a fixed label (fan-out) — never a path, query,
 * userinfo, or port — and it is PII-guarded again at the transmit chokepoint
 * before any upload. The snapshot number {@code events.retryBurstMax10s} is an
 * anonymous COUNT only, never a host or URL. Everything is silenced entirely by
 * the kill-switch.
 *
 * Tunables and algorithms are byte-parity with the Java / Go siblings.
 */
final class LiveDetectors
{
    private function __construct()
    {
    }

    // ── Tunables (byte-parity with LiveDetectors.java / live_detectors.go) ───

    /** Rolling window for counting outbound attempts to one host (ms). */
    public const RETRY_WINDOW_MS = 10_000;
    /** Attempts to one host within the window before it counts as a storm. */
    public const RETRY_STORM_THRESHOLD = 5;
    /** Median inter-attempt gap (ms) at/above which we assume real backoff. */
    public const RETRY_BACKOFF_MS = 1_000;
    /** Cap distinct tracked hosts so a fan-out app can't grow this unbounded. */
    public const MAX_TRACKED_HOSTS = 200;
    /** Many DISTINCT hosts contacted this close together (ms) = a fan-out. */
    public const CLUSTER_WINDOW_MS = 50;
    /** Distinct hosts within CLUSTER_WINDOW_MS at/above which we flag it. */
    public const CLUSTER_HOST_THRESHOLD = 3;

    // ── Cross-request state keys (PHP has no process to hold these) ──────────

    /** host => ascending attempt timestamps (ms), trimmed to the window. */
    private const KEY_ATTEMPTS = 'live.attemptsByHost';
    /** Set (as a map host=>true) of hosts never counted — the kit's own host. */
    private const KEY_IGNORED = 'live.ignoredHosts';
    /** Session-peak same-host qualifying attempt count. */
    private const KEY_BURST_PEAK = 'live.retryBurstPeak';

    // ── Outbound outcome buckets (forwarded to the network meter axis) ───────
    // Kept numerically stable with the siblings; the network meter reads them.
    public const OUTBOUND_OK = 0;
    public const OUTBOUND_ERROR = 1;
    public const OUTBOUND_TIMEOUT = 2;
    public const OUTBOUND_STALL = 3;

    /**
     * Extract a bare, non-PII hostname from a URL/origin/host string. Returns
     * "" when nothing host-shaped is present. Never throws. Path, query,
     * userinfo, and port are all discarded.
     */
    public static function hostOf(?string $target): string
    {
        if ($target === null || $target === '') {
            return '';
        }

        return (string) Safe::run(static function () use ($target): string {
            $s = trim($target);
            if (str_contains($s, '://')) {
                $h = parse_url($s, PHP_URL_HOST);
                if (is_string($h) && $h !== '') {
                    return strtolower($h);
                }
                // fall through to the manual path on a parse failure
            }
            $at = strrpos($s, '@');
            if ($at !== false) {
                $s = substr($s, $at + 1); // strip userinfo
            }
            $s = explode('/', $s, 2)[0];
            $s = explode('?', $s, 2)[0];
            $s = explode(':', $s, 2)[0]; // strip port

            return strtolower($s);
        }, '');
    }

    /**
     * Exclude a host from retry-storm accounting (Boosthis's own endpoint, so
     * the kit's flushes never self-trigger a finding naming our own API host).
     * Idempotent; only a bare hostname is ever stored.
     */
    public static function ignoreHost(?string $target): void
    {
        $host = self::hostOf($target);
        if ($host === '') {
            return;
        }
        Safe::fire(static function () use ($host): void {
            Store::update(self::KEY_IGNORED, static function ($cur) use ($host): array {
                $set = is_array($cur) ? $cur : [];
                $set[$host] = true;

                return $set;
            }, []);
        });
    }

    /**
     * Record one outbound attempt to {@code target} (a URL, origin, or bare
     * host). Safe to call on every request; a no-op under the kill-switch.
     * Boosthis never patches the host's HTTP client, so the host app wires this
     * one-liner into the client/interceptor it already owns. Never throws.
     */
    public static function recordOutboundAttempt(?string $target): void
    {
        if (RuntimeFlags::disabled()) {
            return;
        }
        // The result hook has no target of its own. Start every attempted pair
        // by clearing any abandoned predecessor, including attempts that turn
        // out to be bare-host or ignored and therefore form no identity.
        RepeatedWork::discardPendingOutbound();
        $host = self::hostOf($target);
        if ($host === '') {
            return;
        }
        Safe::fire(static function () use ($host, $target): void {
            $ignored = Store::get(self::KEY_IGNORED, []);
            if (is_array($ignored) && !empty($ignored[$host])) {
                return;
            }
            // The ignored-host decision must happen before the full target is
            // hashed. RepeatedWork itself refuses targets without "://".
            RepeatedWork::noteOutboundStart($target);
            $now = Telemetry::nowMs();
            Store::update(self::KEY_ATTEMPTS, static function ($cur) use ($host, $now): array {
                $byHost = is_array($cur) ? $cur : [];
                if (!isset($byHost[$host])) {
                    if (count($byHost) >= self::MAX_TRACKED_HOSTS) {
                        return $byHost; // bounded — drop new hosts
                    }
                    $byHost[$host] = [];
                }
                $arr = is_array($byHost[$host]) ? $byHost[$host] : [];
                $arr[] = $now;
                $cutoff = $now - self::RETRY_WINDOW_MS;
                while (!empty($arr) && $arr[0] < $cutoff) {
                    array_shift($arr);
                }
                $byHost[$host] = array_values($arr);

                return $byHost;
            }, []);

            // Session-peak burst counter (anonymous number for
            // events.retryBurstMax10s): only qualifying storms count — enough
            // attempts in the window AND no real backoff between them.
            $byHost = Store::get(self::KEY_ATTEMPTS, []);
            $arr = (is_array($byHost) && isset($byHost[$host]) && is_array($byHost[$host]))
                ? $byHost[$host] : [];
            if (
                count($arr) >= self::RETRY_STORM_THRESHOLD
                && self::medianGapMs($arr) < self::RETRY_BACKOFF_MS
            ) {
                $size = count($arr);
                Store::update(self::KEY_BURST_PEAK, static function ($cur) use ($size): int {
                    $cur = is_int($cur) ? $cur : 0;

                    return $size > $cur ? $size : $cur;
                }, 0);
            }
        });
    }

    /**
     * Record the COMPLETION of an outbound attempt previously observed via
     * {@link recordOutboundAttempt()} — its duration (ms) and a coarse outcome
     * bucket. Forwards counts + durations only to the additive network meter
     * axis — never a host, URL, status, or header. The PHP network meter is
     * written by a sibling; this method is the SAME host-owned observation
     * surface as the retry-storm detector (no second interception layer). It
     * degrades to a no-op when that meter is not present. No-op under the
     * kill-switch; never throws.
     */
    public static function recordOutboundResult(int $durationMs, int $outcome): void
    {
        if (RuntimeFlags::disabled()) {
            return;
        }
        Safe::fire(static function () use ($durationMs, $outcome): void {
            // Every outcome, including errors/timeouts/stalls, is still a call.
            // Consuming first also prevents a failed call leaking or being
            // counted by a later result.
            RepeatedWork::noteOutboundEnd((float) $durationMs);
            // The network meter axis is a separate sibling class; forward only
            // if it exists so this kit degrades cleanly when it is absent.
            if (method_exists(MeterAxes::class, 'recordOutboundResult')) {
                MeterAxes::recordOutboundResult($durationMs, $outcome);
            }
        });
    }

    /**
     * Median inter-attempt gap of an ascending timestamp list (0 for < 2).
     *
     * @param list<int> $sortedTs
     */
    public static function medianGapMs(array $sortedTs): float
    {
        $n = count($sortedTs);
        if ($n < 2) {
            return 0.0;
        }
        $gaps = [];
        for ($i = 1; $i < $n; $i++) {
            $gaps[] = $sortedTs[$i] - $sortedTs[$i - 1];
        }
        sort($gaps);
        $g = count($gaps);
        $mid = intdiv($g, 2);
        if ($g % 2 === 0) {
            return ($gaps[$mid - 1] + $gaps[$mid]) / 2.0;
        }

        return (float) $gaps[$mid];
    }

    /**
     * Drain the current live-detector findings so they ride the same
     * crossCutting + snapshot path as every other finding. Empty under the
     * kill-switch. Never throws.
     *
     * @return list<array<string,mixed>>
     */
    public static function collectDetectorFindings(): array
    {
        if (RuntimeFlags::disabled()) {
            return [];
        }

        /** @var list<array<string,mixed>> $out */
        $out = Safe::run(static function (): array {
            $now = Telemetry::nowMs();
            $byHost = Store::get(self::KEY_ATTEMPTS, []);
            $byHost = is_array($byHost) ? $byHost : [];

            $findings = self::collectRetryStorms($byHost, $now);
            foreach (self::collectConcurrentClusters($byHost, $now) as $f) {
                $findings[] = $f;
            }

            return $findings;
        }, []);

        return $out;
    }

    /**
     * @param array<string,mixed> $byHost
     * @return list<array<string,mixed>>
     */
    private static function collectRetryStorms(array $byHost, int $now): array
    {
        $out = [];
        $cutoff = $now - self::RETRY_WINDOW_MS;
        foreach ($byHost as $host => $ts) {
            $arr = is_array($ts) ? array_values($ts) : [];
            while (!empty($arr) && $arr[0] < $cutoff) {
                array_shift($arr);
            }
            if (count($arr) < self::RETRY_STORM_THRESHOLD) {
                continue;
            }
            if (self::medianGapMs($arr) >= self::RETRY_BACKOFF_MS) {
                continue; // backoff present → fine
            }
            // Honest measurements: burst span (first→last, ms) + attempt count.
            $out[] = [
                'kind' => 'retry-storm',
                'name' => (string) $host,
                'p95' => (int) ($arr[count($arr) - 1] - $arr[0]),
                'count' => count($arr),
            ];
        }

        return $out;
    }

    /**
     * Detect an outbound fan-out burst: many DISTINCT hosts all contacted within
     * a very short window. Algorithm byte-identical to the Java / Go siblings
     * (flatten in-window (ts, host) pairs, sort by ts, slide a CLUSTER_WINDOW_MS
     * window, track the largest distinct-host set).
     *
     * @param array<string,mixed> $byHost
     * @return list<array<string,mixed>>
     */
    private static function collectConcurrentClusters(array $byHost, int $now): array
    {
        $cutoff = $now - self::RETRY_WINDOW_MS;
        /** @var list<array{0:int,1:int}> $idx */
        $idx = []; // [ts, hostIndex]
        $hostIndex = [];
        $nextIndex = 0;
        foreach ($byHost as $host => $ts) {
            $arr = is_array($ts) ? $ts : [];
            foreach ($arr as $t) {
                if ($t >= $cutoff) {
                    if (!isset($hostIndex[$host])) {
                        $hostIndex[$host] = $nextIndex++;
                    }
                    $idx[] = [(int) $t, $hostIndex[$host]];
                }
            }
        }
        if (count($idx) < self::CLUSTER_HOST_THRESHOLD) {
            return [];
        }
        usort($idx, static fn ($a, $b) => $a[0] <=> $b[0]);

        $bestHosts = 0;
        $bestSpan = 0;
        $left = 0;
        /** @var array<int,int> $windowHosts */
        $windowHosts = []; // hostIndex => count in window
        $count = count($idx);
        for ($right = 0; $right < $count; $right++) {
            $h = $idx[$right][1];
            $windowHosts[$h] = ($windowHosts[$h] ?? 0) + 1;
            while ($idx[$right][0] - $idx[$left][0] > self::CLUSTER_WINDOW_MS) {
                $lh = $idx[$left][1];
                $c = ($windowHosts[$lh] ?? 0) - 1;
                if ($c <= 0) {
                    unset($windowHosts[$lh]);
                } else {
                    $windowHosts[$lh] = $c;
                }
                $left++;
            }
            $distinct = count($windowHosts);
            if ($distinct > $bestHosts) {
                $bestHosts = $distinct;
                $bestSpan = $idx[$right][0] - $idx[$left][0];
            }
        }
        if ($bestHosts < self::CLUSTER_HOST_THRESHOLD) {
            return [];
        }

        return [[
            'kind' => 'api-thundering-herd',
            'name' => 'outbound-fan-out',
            'p95' => (int) $bestSpan,
            'count' => (int) $bestHosts,
        ]];
    }

    /**
     * Session-peak same-destination retry-burst strength (attempts inside one
     * rolling window with no backoff); 0 when no storm was observed. Ships as
     * the anonymous {@code events.retryBurstMax10s} number (count only, never a
     * host/URL), mirroring the siblings. No-op (0) under the kill-switch.
     */
    public static function peakRetryBurst(): int
    {
        if (RuntimeFlags::disabled()) {
            return 0;
        }

        return (int) Safe::run(static function (): int {
            $v = Store::get(self::KEY_BURST_PEAK, 0);

            return is_int($v) ? $v : 0;
        }, 0);
    }

    /** Wipe all detector state (wired into forget()). Idempotent. */
    public static function clearDetectors(): void
    {
        Safe::fire(static function (): void {
            Store::delete(self::KEY_ATTEMPTS);
            Store::delete(self::KEY_IGNORED);
            Store::delete(self::KEY_BURST_PEAK);
        });
    }
}
