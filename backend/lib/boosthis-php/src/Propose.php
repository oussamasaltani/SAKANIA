<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * Learning-loop WRITE client — the PHP sibling of the Java runtime's
 * Propose.java, the Go runtime's propose.go,
 * lib/boosthis-runtime-node/src/proposeRule.ts, and boosthis-py's propose_rule.
 * When the developer's own AI reports a slow pattern that no shipped rule
 * covers (boosthis.report_unmatched_pattern), this forwards a privacy-safe
 * PROPOSAL to the maintainer's review queue (POST /rules/propose). The
 * maintainer approves or rejects it by hand — nothing here ever ships a rule.
 *
 * TWO privacy tiers, decided by the SERVER:
 *  - TIER A (always): a demand signal only — runtime, a coarse category,
 *    severity + timing buckets, a NON-reversible sha256 of the pattern, and an
 *    occurrence count. No free text, no code, no identifiers.
 *  - TIER B (only when the app proves control of a connected install): also the
 *    raw pattern text and any AI-drafted rule (id/title/whenToApply/fix). Proof
 *    is the install's read token in the X-Boosthis-Install-* headers; the invite
 *    key alone never unlocks it. The server degrades unproven drafts.
 *
 * The payload rides the shared PII guard before any send; BOOSTHIS_DISABLED
 * silences it; it is entirely fail-open — every error is swallowed so a
 * best-effort proposal can never block or break the tool the developer's AI
 * called.
 *
 * PHP DIFFERENCES from the Java sibling (same names, documented here):
 *  - Java runs the payload through Transmit.safeTransmitInternal, which accepts
 *    an extra trusted-headers map. The PHP Transmit chokepoint has no public
 *    trusted-headers seam, so Propose guards the body itself (Pii::assertNoPii,
 *    the SAME check the chokepoint runs) and then POSTs through its own
 *    swappable sender that adds the invite-key bearer + the X-Boosthis-Install-*
 *    proof headers AFTER the guard, exactly as the note in the other runtimes
 *    describes. The default sender uses the same curl/stream transport as
 *    Transmit.
 *  - Java fires the network on a daemon thread. PHP has no cheap threads on a
 *    typical request; the send is best-effort and synchronous but fully
 *    guarded by Safe, with a short bounded timeout, so it can never break the
 *    caller. The public method still returns void with fire-and-forget
 *    semantics.
 */
final class Propose
{
    /** @var list<string> */
    private const CATEGORIES = [
        'startup',
        'navigation',
        'interaction',
        'rendering',
        'network',
        'data',
        'memory',
        'other',
    ];

    private const RULE_ID_RE = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
    private const WS_RE = '/\s+/';

    /** Bounds the proposal POST, matching Community's timeout. */
    private const TIMEOUT_MS = 5000;

    /**
     * The swappable POST transport (test seam). Guards nothing itself — the
     * body is guarded by the caller before this runs.
     *
     * @var (callable(string,array<string,mixed>,array<string,string>,int):int)|null
     */
    private static $sender = null;

    private function __construct()
    {
    }

    /**
     * Lowercase-hex sha256 of a UTF-8 string.
     */
    public static function sha256Hex(string $s): string
    {
        return hash('sha256', $s);
    }

    /**
     * The non-reversible dedup + distinct-project counting key: sha256 of the
     * whitespace-normalized, lower-cased pattern (64 lowercase hex chars). The
     * raw pattern is NEVER part of the tier-A signal — only this hash is.
     */
    public static function patternSignature(string $pattern): string
    {
        $lower = mb_strtolower($pattern);
        $norm = trim((string) preg_replace(self::WS_RE, ' ', $lower));

        return self::sha256Hex($norm);
    }

    /**
     * Maps the tool's `language` enum to the wire `runtime` enum, defaulting to
     * this runtime. PHP maps to the server runtime tag "php".
     */
    public static function runtimeFromLanguage(?string $language): string
    {
        switch ($language) {
            case 'node':
                return 'node';
            case 'python':
                return 'py';
            case 'react-native':
                return 'rn';
            case 'go':
                return 'go';
            case 'java':
                return 'java';
            case 'php':
                return 'php';
            default:
                return 'php';
        }
    }

    /**
     * Buckets the observed latency against the shared TTI band. Absent or
     * non-finite input degrades to "warn" (an unmatched pattern is, by nature,
     * a suspected problem).
     */
    public static function timingBucketFor(float $observedMs, bool $present): string
    {
        if (!$present || is_nan($observedMs) || is_infinite($observedMs)) {
            return 'warn';
        }
        if ($observedMs <= Thresholds::TTI_GOOD) {
            return 'good';
        }
        if ($observedMs >= Thresholds::TTI_POOR) {
            return 'poor';
        }

        return 'warn';
    }

    /** Maps the timing bucket to a severity bucket. */
    public static function severityForTiming(string $timing): string
    {
        switch ($timing) {
            case 'poor':
                return 'high';
            case 'warn':
                return 'med';
            default:
                return 'low';
        }
    }

    /** Clamps a free category to the 8-value enum, else "other". */
    public static function normalizeCategory(?string $category): string
    {
        return $category !== null && in_array($category, self::CATEGORIES, true) ? $category : 'other';
    }

    /** Caps a string to max code points (never splitting a multibyte character). */
    public static function truncateRunes(?string $s, int $max): string
    {
        if ($s === null || $max <= 0) {
            return '';
        }
        if (mb_strlen($s) <= $max) {
            return $s;
        }

        return mb_substr($s, 0, $max);
    }

    /**
     * Includes a tier-B free-text field ONLY if present and it passes the
     * shared PII guard. Dropping a PII-tripping field (rather than aborting)
     * keeps the tier-A demand signal alive while never sending PII-shaped free
     * text — the server re-runs the same guard as the authority. Truncate
     * first, then guard.
     */
    public static function cleanText(?string $value, int $maxLen): string
    {
        $trimmed = self::truncateRunes($value, $maxLen);
        if ($trimmed === '') {
            return '';
        }
        if (Pii::checkNoPii($trimmed) !== null) {
            return '';
        }

        return $trimmed;
    }

    /**
     * First non-empty, trimmed value among the named env vars, or "". Replaces
     * Java's RuntimeFlags.firstEnv, keeping the BOOSTEN_* fallbacks.
     */
    private static function firstEnv(string ...$names): string
    {
        foreach ($names as $n) {
            $v = RuntimeFlags::env($n);
            if ($v !== null) {
                $v = trim($v);
                if ($v !== '') {
                    return $v;
                }
            }
        }

        return '';
    }

    /**
     * The install id + read token that prove control of a connected install
     * (the same env vars the stdio server uses for live reads). Returns null
     * unless both are present.
     *
     * @return array{0:string,1:string}|null
     */
    private static function installCreds(): ?array
    {
        $id = self::firstEnv('BOOSTHIS_INSTALL_ID', 'BOOSTEN_INSTALL_ID');
        $tok = self::firstEnv('BOOSTHIS_READ_TOKEN', 'BOOSTEN_READ_TOKEN');
        if ($id === '' || $tok === '') {
            return null;
        }

        return [$id, $tok];
    }

    /**
     * Fire-and-forget a rule PROPOSAL to the maintainer's review queue. Fully
     * fail-open: never blocks the caller in any way that can throw, and never
     * throws. A no-op when there is no invite key or under BOOSTHIS_DISABLED.
     * Called by the MCP report_unmatched_pattern tool.
     *
     * @param array{proposedRuleId?:string,title?:string,whenToApply?:string,fixTemplate?:string}|null $draft
     */
    public static function reportUnmatchedPattern(
        string $pattern,
        ?string $language,
        float $observedMs,
        bool $observedPresent,
        ?string $category,
        ?array $draft = null
    ): void {
        Safe::fire(static function () use (
            $pattern,
            $language,
            $observedMs,
            $observedPresent,
            $category,
            $draft
        ): void {
            self::proposeUnmatchedPattern(
                $pattern,
                $language,
                $observedMs,
                $observedPresent,
                $category,
                $draft ?? []
            );
        });
    }

    /**
     * Build + send one proposal. Visible so tests can exercise it directly.
     * Never throws.
     *
     * @param array{proposedRuleId?:string,title?:string,whenToApply?:string,fixTemplate?:string} $draft
     */
    public static function proposeUnmatchedPattern(
        string $pattern,
        ?string $language,
        float $observedMs,
        bool $observedPresent,
        ?string $category,
        array $draft
    ): void {
        Safe::fire(static function () use (
            $pattern,
            $language,
            $observedMs,
            $observedPresent,
            $category,
            $draft
        ): void {
            $key = (string) (Telemetry::projectKey() ?? '');
            // Gate: a proposal requires an invite key (the distributable fix
            // scope). Without one this is an unregistered install — stay fully
            // on-device.
            if ($key === '') {
                return;
            }
            // Defensive kill-switch check; the send path also honors it.
            if (RuntimeFlags::disabled()) {
                return;
            }

            $timingBucket = self::timingBucketFor($observedMs, $observedPresent);
            $body = [
                'runtime' => self::runtimeFromLanguage($language),
                'category' => self::normalizeCategory($category),
                'severityBucket' => self::severityForTiming($timingBucket),
                'timingBucket' => $timingBucket,
                'signature' => self::patternSignature($pattern),
                'occurrences' => 1,
            ];

            // TIER B: only when we can prove control of a connected install.
            // The server still decides the tier — we just attach the proof
            // headers + drafted text.
            $trusted = [];
            $creds = self::installCreds();
            if ($creds !== null) {
                $trusted = [
                    'X-Boosthis-Install-Id' => $creds[0],
                    'X-Boosthis-Install-Token' => $creds[1],
                ];
                $rawPattern = self::cleanText($pattern, 2000);
                if ($rawPattern !== '') {
                    $body['pattern'] = $rawPattern;
                }
                $candidate = self::truncateRunes($draft['proposedRuleId'] ?? '', 80);
                if ($candidate !== '' && preg_match(self::RULE_ID_RE, $candidate) === 1) {
                    $body['proposedRuleId'] = $candidate;
                }
                $title = self::cleanText($draft['title'] ?? '', 160);
                if ($title !== '') {
                    $body['title'] = $title;
                }
                $whenToApply = self::cleanText($draft['whenToApply'] ?? '', 2000);
                if ($whenToApply !== '') {
                    $body['whenToApply'] = $whenToApply;
                }
                $fixTemplate = self::cleanText($draft['fixTemplate'] ?? '', 8000);
                if ($fixTemplate !== '') {
                    $body['fixTemplate'] = $fixTemplate;
                }
            }

            // The body passes the SAME PII guard the shared chokepoint runs.
            // The invite key (bearer) + trusted headers (install proof) are
            // Boosthis-server credentials added AFTER the guard by the sender.
            Pii::assertNoPii($body);
            self::send(Community::endpointBase() . '/rules/propose', $body, $key, $trusted);
        });
    }

    /**
     * POST the guarded body with the invite-key bearer + the trusted install
     * proof headers. Uses the swappable sender when set (tests); otherwise the
     * same curl/stream transport as Transmit. A no-op under BOOSTHIS_DISABLED.
     *
     * @param array<string,mixed> $body
     * @param array<string,string> $trusted
     */
    private static function send(string $url, array $body, string $inviteKey, array $trusted): void
    {
        if (RuntimeFlags::disabled()) {
            return;
        }
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => Transmit::userAgent(),
            'Authorization' => 'Bearer ' . $inviteKey,
        ];
        foreach ($trusted as $k => $v) {
            $headers[$k] = $v;
        }

        if (self::$sender !== null) {
            Safe::fire(static function () use ($url, $body, $headers): void {
                (self::$sender)($url, $body, $headers, self::TIMEOUT_MS);
            });

            return;
        }

        Safe::fire(static function () use ($url, $body, $headers): void {
            $encoded = Json::encode($body);
            if ($encoded === null) {
                return;
            }
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                if ($ch === false) {
                    return;
                }
                $hdr = [];
                foreach ($headers as $k => $v) {
                    $hdr[] = $k . ': ' . $v;
                }
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_HTTPHEADER => $hdr,
                    CURLOPT_POSTFIELDS => $encoded,
                    CURLOPT_TIMEOUT_MS => self::TIMEOUT_MS,
                    CURLOPT_CONNECTTIMEOUT_MS => min(self::TIMEOUT_MS, 3000),
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_FAILONERROR => false,
                ]);
                curl_exec($ch);
                curl_close($ch);

                return;
            }

            $hdr = '';
            foreach ($headers as $k => $v) {
                $hdr .= $k . ': ' . $v . "\r\n";
            }
            $ctx = stream_context_create(['http' => [
                'method' => 'POST',
                'header' => $hdr,
                'content' => $encoded,
                'timeout' => self::TIMEOUT_MS / 1000,
                'ignore_errors' => true,
                'follow_location' => 0,
            ]]);
            @file_get_contents($url, false, $ctx);
        });
    }

    /**
     * Test seam: swap the POST transport (null restores the real one).
     *
     * @param (callable(string,array<string,mixed>,array<string,string>,int):int)|null $fn
     */
    public static function setSenderForTests(?callable $fn): void
    {
        self::$sender = $fn;
    }
}
