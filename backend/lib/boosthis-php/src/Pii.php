<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * PII denylist + guard — a verbatim port of Java's Pii.java (itself a port of
 * Python's pii.py, itself a port of the RN/Node no-pii.ts). Inspects BOTH field
 * names AND string values. assertNoPii() throws before any outbound send if a
 * payload trips the denylist or a value pattern.
 *
 * PARITY WARNING: the denylist, the allowlist, every regex and the ORDER of the
 * checks must stay byte-identical to the server's pii.ts. Drift does not fail
 * loudly — it silently drops whole uploads on one side of the wire.
 */
final class Pii
{
    private function __construct()
    {
    }

    /** Canonical set of field names that must never appear in a transmitted payload. */
    public const PII_DENYLIST = [
        // identity
        'email', 'mail', 'username', 'userid', 'user_id', 'uid',
        'firstname', 'first_name', 'lastname', 'last_name',
        'fullname', 'full_name', 'phone', 'mobile', 'tel',
        'ssn', 'nationalid', 'national_id', 'taxid', 'tax_id',
        'dob', 'birthdate', 'birthday',
        // account/auth secrets
        'password', 'passwd', 'secret',
        'apikey', 'api_key', 'token', 'auth', 'authorization',
        'session', 'cookie', 'bearer', 'jwt',
        'creditcard', 'credit_card', 'cardnumber', 'card_number', 'cvv',
        'iban', 'swift', 'routingnumber', 'routing_number',
        // device-as-identity
        'deviceid', 'device_id', 'advertisingid', 'advertising_id',
        'idfa', 'idfv', 'macaddress', 'mac_address', 'imei',
        // network identity
        'ipaddress', 'ip_address', 'ip', 'ipv4', 'ipv6',
        'useragent', 'user_agent',
        // location
        'latitude', 'longitude', 'lat', 'lng', 'lon', 'geohash',
        'address', 'street', 'city', 'zipcode', 'zip_code',
        'postalcode', 'postal_code',
        // free-form content (high-risk for incidental PII)
        'message', 'content', 'body', 'text', 'comment', 'note',
        'value', 'input', 'query', 'search',
    ];

    /**
     * Keys that look denylisted but are explicitly safe (code-defined metadata).
     *
     * Kept in lock-step across every runtime. The awkward-looking entries are
     * deliberate: an axis KEY such as cookieExposure normalizes to
     * "cookieexposure", which embeds the denied "cookie" fragment, so without
     * the exemption the kit's own guard silently drops the ENTIRE snapshot the
     * moment that axis warms up. Each one carries integer counters only, never
     * a matched value.
     */
    public const ALLOWLIST = [
        'screen', 'screenname', 'screen_name',
        'appversion', 'app_version',
        'osversion', 'os_version',
        'osname', 'os_name',
        'findingid', 'finding_id',
        'ruleid', 'rule_id',
        'score', 'rating', 'perception',
        'sessionid', 'session_id',
        // monitorContention / lockContention / gilContention twins in the
        // Java, Go, Python and RN kits — "content" is a substring of
        // "contention".
        'monitorcontention', 'monitor_contention',
        'lockcontention', 'lock_contention',
        // leakWatch per-category counters ("secret" in secretCount).
        'secretcount', 'stackcount', 'piicount',
        // cookieExposure axis key.
        'cookieexposure',
    ];

    private const EMAIL_RE = '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/';
    private const IPV4_RE = '/\b(?:\d{1,3}\.){3}\d{1,3}\b/';
    private const IPV6_FULL_RE = '/^[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){7}$/i';
    private const IPV6_HEX_ONLY_RE = '/^[0-9a-f:]+$/i';
    private const JWT_RE = '/eyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+/';
    private const BEARER_RE = '/bearer\s+\S{8,}/i';
    private const IPV6_CANDIDATE_RE = '/[0-9a-f:]{4,45}/i';
    private const UUID_RE =
        '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i';
    private const LONG_NUMERIC_RE = '/\b\d{6,}\b/';
    private const PHONE_RE =
        '/(?<![0-9a-fA-F])(?:\+\d{1,3}[\s\-.])?\(?\d{3}\)?[\s\-.]\d{3}[\s\-.]\d{4}(?![0-9a-fA-F])/';
    private const LABEL_SPACE_RE = '/\s/';

    /**
     * Narrowly-scoped structural exception: a canonical method-path label
     * ("GET /users/:id" / "screen:GET /users/:id") is exempted ONLY from the
     * space heuristic; every later PII check (email/JWT/IP/phone/UUID/
     * long-numeric-id) still runs unconditionally. Whole-string match, matching
     * Java's Matcher.matches() — drift here silently drops every route label.
     */
    private const STRUCTURAL_METHOD_PATH_RE =
        '/^(?:screen:)?(?:GET|POST|PUT|DELETE|PATCH|HEAD|OPTIONS|TRACE|CONNECT) \/\S*$/';

    public static function normalize(string $name): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($name));
    }

    /** @return list<string> */
    public static function tokenize(string $name): array
    {
        $s = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $name);
        $s = (string) preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1 $2', $s);
        $out = [];
        foreach (preg_split('/[^a-zA-Z0-9]+/', $s) ?: [] as $tok) {
            if ($tok !== '') {
                $out[] = strtolower($tok);
            }
        }

        return $out;
    }

    /** Returns the denied denylist entry a field name trips, or null when clean. */
    public static function findDeniedFragment(string $name): ?string
    {
        $normalized = self::normalize($name);
        if (in_array($normalized, self::ALLOWLIST, true)) {
            return null;
        }
        $tokens = self::tokenize($name);

        // Pass 1: exact normalized match + per-token match.
        foreach (self::PII_DENYLIST as $denied) {
            $dNorm = self::normalize($denied);
            if ($normalized === $dNorm) {
                return $denied;
            }
            foreach ($tokens as $tok) {
                if ($tok === $dNorm) {
                    return $denied;
                }
            }
        }

        // Pass 2: concatenated-fragment containment for denylist entries >= 5 chars.
        foreach (self::PII_DENYLIST as $denied) {
            $dNorm = self::normalize($denied);
            if (strlen($dNorm) < 5) {
                continue;
            }
            if (str_contains($normalized, $dNorm)) {
                return $denied;
            }
        }

        return null;
    }

    public static function looksLikeIpv6(string $val): bool
    {
        if (strlen($val) < 3) {
            return false;
        }
        if (preg_match(self::IPV6_FULL_RE, $val) === 1) {
            return true;
        }
        if (!str_contains($val, '::')) {
            return false;
        }
        if (preg_match(self::IPV6_HEX_ONLY_RE, $val) !== 1) {
            return false;
        }
        if (strpos($val, '::') !== strrpos($val, '::')) {
            return false;
        }
        $groups = 0;
        foreach (explode(':', $val) as $g) {
            if ($g !== '') {
                $groups++;
            }
        }

        return $groups >= 1 && $groups <= 7;
    }

    private static function containsEmbeddedIpv6(string $val): bool
    {
        if (preg_match_all(self::IPV6_CANDIDATE_RE, $val, $m) === false) {
            return false;
        }
        foreach ($m[0] ?? [] as $candidate) {
            if (self::looksLikeIpv6($candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True if $val contains an email address, using the kit's own EMAIL_RE
     * (shared with findDeniedValue) so the leakWatch axis reuses the SAME
     * pattern rather than duplicating it. Content-free: returns only a boolean,
     * never the match.
     */
    public static function containsEmail(?string $val): bool
    {
        return $val !== null && $val !== '' && preg_match(self::EMAIL_RE, $val) === 1;
    }

    /** Return a fragment tag if the string value looks like PII, else null. */
    public static function findDeniedValue(?string $val): ?string
    {
        if ($val === null || strlen($val) < 5) {
            return null;
        }
        if (preg_match(self::EMAIL_RE, $val) === 1) {
            return '~email';
        }
        if (preg_match(self::JWT_RE, $val) === 1) {
            return '~jwt';
        }
        if (preg_match(self::BEARER_RE, $val) === 1) {
            return '~bearer';
        }
        if (preg_match(self::IPV4_RE, $val) === 1) {
            return '~ipv4';
        }
        if (self::containsEmbeddedIpv6($val)) {
            return '~ipv6';
        }
        if (preg_match(self::PHONE_RE, $val) === 1) {
            return '~phone';
        }

        return null;
    }

    /**
     * Return a PII tag if a route/screen label contains a high-risk pattern.
     * Adds whitespace, UUID, and long-numeric-id checks on top of the general
     * value guard. Returns null when clean. Carries the same narrowly-scoped
     * structural method-path exception as every other runtime: a canonical
     * "GET /path" label skips ONLY the space heuristic — all value-level PII
     * checks below still run on the full label.
     */
    public static function checkRouteLabel(?string $label): ?string
    {
        if ($label === null) {
            return null;
        }
        if (
            preg_match(self::LABEL_SPACE_RE, $label) === 1
            && preg_match(self::STRUCTURAL_METHOD_PATH_RE, $label) !== 1
        ) {
            return '~space-separated-label';
        }
        $base = self::findDeniedValue($label);
        if ($base !== null) {
            return $base;
        }
        if (preg_match(self::UUID_RE, $label) === 1) {
            return '~uuid';
        }
        if (preg_match(self::LONG_NUMERIC_RE, $label) === 1) {
            return '~numeric-id';
        }

        return null;
    }

    /**
     * Walk a payload and return the first hit as
     * ['path' => string, 'fieldName' => string, 'matchedFragment' => string],
     * or null when the payload is clean.
     *
     * @param mixed $payload
     * @return array{path:string,fieldName:string,matchedFragment:string}|null
     */
    public static function checkNoPii($payload, string $path = '$'): ?array
    {
        if (is_string($payload)) {
            $denied = self::findDeniedValue($payload);
            if ($denied !== null) {
                $pos = strrpos($path, '.');
                $fieldName = $pos === false ? $path : substr($path, $pos + 1);

                return ['path' => $path, 'fieldName' => $fieldName, 'matchedFragment' => $denied];
            }

            return null;
        }

        if (is_array($payload)) {
            foreach ($payload as $key => $child) {
                if (is_int($key)) {
                    $inner = self::checkNoPii($child, $path . '[' . $key . ']');
                    if ($inner !== null) {
                        return $inner;
                    }
                    continue;
                }
                $keyStr = (string) $key;
                $childPath = $path . '.' . $keyStr;
                $matched = self::findDeniedFragment($keyStr);
                if ($matched !== null) {
                    return [
                        'path' => $childPath,
                        'fieldName' => $keyStr,
                        'matchedFragment' => $matched,
                    ];
                }
                $inner = self::checkNoPii($child, $childPath);
                if ($inner !== null) {
                    return $inner;
                }
            }

            return null;
        }

        if ($payload instanceof \stdClass) {
            return self::checkNoPii((array) $payload, $path);
        }

        return null;
    }

    /**
     * Throw PiiDetectedException if the payload contains any PII.
     *
     * @param mixed $payload
     */
    public static function assertNoPii($payload): void
    {
        $hit = self::checkNoPii($payload);
        if ($hit !== null) {
            throw new PiiDetectedException(
                $hit['path'],
                $hit['fieldName'],
                $hit['matchedFragment']
            );
        }
    }
}
