<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * The {@code cookieExposure} additive meter axis (PHP) — the parity sibling of
 * the shared cross-runtime "Cookie Exposure" axis (see the Java kit's
 * {@see CookieExposure} equivalent and the Go kit's readCookieExposure). It
 * counts cookies leaving this process WITHOUT their safe flags, observed on the
 * REAL outgoing {@code Set-Cookie} headers — after the framework, any proxy and
 * any CDN have rewritten them — which is exactly what config-reading tools get
 * wrong. Runtime evidence only; NEVER feeds the Speed score; additive and
 * display-only.
 *
 * This meter reports EXPOSURE, never safety. A clean tile means "we did not
 * observe an unsafe flag on the responses we watched" — never "your cookies are
 * safe".
 *
 * ABSOLUTE PRIVACY RULE. The cookie NAME, the cookie VALUE, the header text and
 * the route/path name are NEVER stored, never logged, and never placed in any
 * field, caption or error. Only per-attribute COUNTERS ever cross the wire —
 * the name/value part of every Set-Cookie (everything before the first ';') is
 * discarded without inspection and never leaves the local parse scope.
 *
 * PHP lifecycle note. PHP has no long-lived process, so unlike the Java/Go kits
 * (which hold these counters in static memory for the process lifetime) every
 * counter here lives in {@see Store}, accumulated across requests via a single
 * locked read-modify-write. The observation point is different too: PHP hands us
 * the outgoing headers through {@see \headers_list()} (the live Set-Cookie set,
 * before send when called early enough) and the session cookie template through
 * {@see \session_get_cookie_params()}. We read them, classify by TOKEN, and
 * fold the deltas into the stored counts.
 *
 * Shared wire shape (byte-identical semantics in the Node, Python, Go, Java and
 * PHP kits): {@code { score, rating, setCount, responses, noSecure, noHttpOnly,
 * noSameSite, noneWithoutSecure, oversize }}. The ONLY string field is
 * {@code rating}; every other field is a number.
 *
 * Honest absence. {@see read()} returns {@code null} until at least one cookie
 * has actually been observed being set, so this axis is simply not emitted for
 * an app that sets no cookies — never a green tile.
 */
final class CookieExposure
{
    private function __construct()
    {
    }

    /** The axis key, shared with the other server kits. */
    public const AXIS = 'cookieExposure';

    /** Store key for the accumulated counters (per-project scope). */
    private const KEY = 'cookie_exposure';

    /** Longest single Set-Cookie value browsers reliably keep (UTF-8 bytes). */
    public const COOKIE_MAX_BYTES = 4096;
    /** Set-Cookie values inspected per response (bounded work on the hot path). */
    public const COOKIE_PER_RESPONSE_MAX = 20;
    /** Attribute segments inspected per cookie (bounded work). */
    public const COOKIE_ATTRS_MAX = 20;
    /**
     * Cap on the attribute-list slice we lowercase/split (chars) — a real
     * attribute list is far shorter; this is what bounds the per-cookie work.
     */
    public const COOKIE_ATTR_SLICE_MAX = 2048;
    /** Counter saturation ceiling — counts stop climbing rather than overflow. */
    public const COOKIE_COUNT_MAX = 1000000000;

    private static function bump(int $n): int
    {
        return $n >= self::COOKIE_COUNT_MAX ? self::COOKIE_COUNT_MAX : $n + 1;
    }

    /**
     * Classify ONE raw {@code Set-Cookie} value by TOKEN, never by substring — a
     * substring match ("secure" in value) would read the text "httponly"
     * appearing inside a cookie NAME or VALUE as a present flag, making an unsafe
     * cookie look clean (the one failure mode this meter must never have). The
     * name/value part (before the first ';') is NEVER inspected for attributes.
     *
     * The value is inspected only within this call: the name and value are never
     * split out, never stored, never logged. Work is bounded: the attribute
     * slice is capped before it is lowercased/split, so a huge reflected header
     * cannot impose unbounded allocation or CPU here.
     *
     * @param array<string,int> $c the counter bag to fold this cookie into
     */
    private static function noteOneCookie(string $raw, array &$c): void
    {
        $c['setCount'] = self::bump($c['setCount']);

        $hasSecure = false;
        $hasHttpOnly = false;
        $hasSameSite = false;
        $sameSiteVal = null;

        // (a) Everything before the FIRST ';' is name=value — never inspected.
        //     No ';' at all → the cookie has no attributes.
        $semi = strpos($raw, ';');
        if ($semi !== false && $semi + 1 < strlen($raw)) {
            // (b) Remainder after the first ';', capped so we never copy,
            //     lowercase or split an arbitrarily large attribute list. The
            //     slice length is bounded FIRST so substr copies at most
            //     COOKIE_ATTR_SLICE_MAX chars — never the whole attacker tail.
            $rest = substr($raw, $semi + 1, self::COOKIE_ATTR_SLICE_MAX);
            // (c) Split on ';', at most COOKIE_ATTRS_MAX segments; trim + lower
            //     each SHORT segment only (never the whole header value).
            $segments = explode(';', $rest, self::COOKIE_ATTRS_MAX + 1);
            $limit = min(count($segments), self::COOKIE_ATTRS_MAX);
            for ($i = 0; $i < $limit; $i++) {
                $seg = trim($segments[$i]);
                if ($seg === '') {
                    continue;
                }
                // (d) Split each segment at its FIRST '=': the attribute NAME is
                //     what precedes it and the VALUE what follows, each trimmed
                //     and lowercased independently. This tolerates whitespace
                //     around the '=' ("SameSite = Lax"), which browsers accept —
                //     treating it as absent would be a FALSE exposure finding.
                $eq = strpos($seg, '=');
                $name = strtolower(trim($eq === false ? $seg : substr($seg, 0, $eq)));
                if ($name === 'secure') {
                    $hasSecure = true;
                } elseif ($name === 'httponly') {
                    $hasHttpOnly = true;
                } elseif ($name === 'samesite' && $eq !== false) {
                    // (e) SameSite counts as present ONLY when the segment has an
                    //     '=' — a bare "samesite" with no value is malformed,
                    //     browsers ignore it, so it stays ABSENT.
                    $hasSameSite = true;
                    $sameSiteVal = strtolower(trim(substr($seg, $eq + 1)));
                }
            }
        }

        if (!$hasSecure) {
            $c['noSecure'] = self::bump($c['noSecure']);
        }
        if (!$hasHttpOnly) {
            $c['noHttpOnly'] = self::bump($c['noHttpOnly']);
        }
        if (!$hasSameSite) {
            $c['noSameSite'] = self::bump($c['noSameSite']);
        }
        // SameSite=None without Secure: browsers reject the cookie outright, so
        // the app silently loses the session it thinks it set. A SEPARATE column
        // from noSecure — a cookie can count in both.
        if ($sameSiteVal === 'none' && !$hasSecure) {
            $c['noneWithoutSecure'] = self::bump($c['noneWithoutSecure']);
        }
        // Oversize = UTF-8 byte length of the WHOLE raw value > 4096, computed
        // without an unbounded copy: char length >= UTF-8 byte length is false
        // (UTF-8 never uses fewer bytes than chars), so if the char length is
        // already over the cap it is oversize by definition; only measure the
        // byte length when the char length is within the cap. On PHP strlen()
        // already counts BYTES, so it equals the wire-byte length for the header
        // string the SAPI hands us — matching the Node/Go/Java kits (only the
        // Python kit holds the true ASGI wire bytes and measures those).
        if (strlen($raw) > self::COOKIE_MAX_BYTES) {
            $c['oversize'] = self::bump($c['oversize']);
        }
    }

    /**
     * Observe the current request's outgoing cookies and fold them into the
     * stored counts. Fed ONCE per finished response (the middleware calls this
     * on the finish path). Reads {@see \headers_list()} for the live Set-Cookie
     * headers and {@see \session_get_cookie_params()} so a session started with
     * unsafe params is counted even before any explicit setcookie() call. At
     * most {@value self::COOKIE_PER_RESPONSE_MAX} values are inspected; the rest
     * are ignored (bounded work). Nothing about a cookie is retained beyond the
     * counters. Fully self-guarded — the observation must never disturb the host
     * response and never throws.
     */
    public static function observeResponse(): void
    {
        if (RuntimeFlags::disabled()) {
            return;
        }
        Safe::fire(static function (): void {
            $values = self::collectSetCookieValues();
            if ($values === []) {
                return;
            }
            self::noteCookiesFromResponse($values);
        });
    }

    /**
     * Gather the raw Set-Cookie header values leaving this response. Combines
     * headers_list() (explicit setcookie()/header() calls) with the session
     * cookie template when a session is active, so a misconfigured session is
     * observed even though PHP sends its Set-Cookie internally. Returns raw
     * values only; nothing is stored here.
     *
     * @return list<string>
     */
    private static function collectSetCookieValues(): array
    {
        $out = [];
        if (function_exists('headers_list')) {
            foreach (headers_list() as $header) {
                // Header lines look like "Set-Cookie: name=value; Path=/". Take
                // only Set-Cookie lines and keep the value part after the colon.
                $colon = strpos($header, ':');
                if ($colon === false) {
                    continue;
                }
                $key = strtolower(trim(substr($header, 0, $colon)));
                if ($key !== 'set-cookie') {
                    continue;
                }
                $val = trim(substr($header, $colon + 1));
                if ($val !== '') {
                    $out[] = $val;
                }
            }
        }
        // Session cookie: when a session is active, PHP will emit a Set-Cookie
        // built from these params on the response, but it may not appear in
        // headers_list() at the moment we look. Synthesize a representative
        // Set-Cookie value from the template's ATTRIBUTES ONLY (the session
        // cookie name is included by session_name(), but we deliberately do NOT
        // read the session value and never store the name) so the flags are
        // counted honestly. We only do this when a session is genuinely active.
        if (
            function_exists('session_status')
            && session_status() === PHP_SESSION_ACTIVE
            && function_exists('session_get_cookie_params')
        ) {
            $p = session_get_cookie_params();
            // Build an attribute string that mirrors what PHP will send, so the
            // SAME token parser classifies it. The name/value placeholder before
            // the first ';' is never inspected (contract rule a).
            $attrs = 'sid=1';
            if (($p['lifetime'] ?? 0) > 0) {
                // Expires/Max-Age presence does not affect the three safe flags,
                // but a long lifetime with a large value could trip oversize on
                // real cookies; the session template value is tiny so it never
                // does — included only for parser parity.
                $attrs .= '; Max-Age=' . (int) $p['lifetime'];
            }
            if (!empty($p['secure'])) {
                $attrs .= '; Secure';
            }
            if (!empty($p['httponly'])) {
                $attrs .= '; HttpOnly';
            }
            $sameSite = isset($p['samesite']) ? trim((string) $p['samesite']) : '';
            if ($sameSite !== '') {
                $attrs .= '; SameSite=' . $sameSite;
            }
            $out[] = $attrs;
        }

        return $out;
    }

    /**
     * Fold a batch of raw Set-Cookie values into the stored counts under a
     * single locked read-modify-write. At most COOKIE_PER_RESPONSE_MAX values
     * are inspected. Never throws.
     *
     * @param list<string> $values
     */
    public static function noteCookiesFromResponse(array $values): void
    {
        if (RuntimeFlags::disabled() || $values === []) {
            return;
        }
        Safe::fire(static function () use ($values): void {
            Store::update(self::KEY, static function ($cur) use ($values) {
                $c = self::normalize($cur);
                $seen = 0;
                foreach ($values as $entry) {
                    if ($seen >= self::COOKIE_PER_RESPONSE_MAX) {
                        break;
                    }
                    if (!is_string($entry) || $entry === '') {
                        continue;
                    }
                    $seen++;
                    self::noteOneCookie($entry, $c);
                }
                if ($seen > 0) {
                    $c['responses'] = self::bump($c['responses']);
                }

                return $c;
            }, self::zero());
        });
    }

    /**
     * The {@code cookieExposure} axis as a closed map, or {@code null} when no
     * cookie has been observed being set (honest absence — never a green tile
     * for an app with no cookies). The ONLY string field is {@code rating};
     * every other field is a number. No cookie name/value/header/path ever
     * appears.
     *
     * @return array<string,int|string>|null
     */
    public static function read(): ?array
    {
        if (RuntimeFlags::disabled()) {
            return null;
        }

        return Safe::run(static function (): ?array {
            $c = self::normalize(Store::get(self::KEY, null));
            $set = $c['setCount'];
            if ($set <= 0) {
                return null; // honest absence — axis simply not emitted
            }
            $noSecure = $c['noSecure'];
            $noHttpOnly = $c['noHttpOnly'];
            $noSameSite = $c['noSameSite'];
            $noneWithoutSecure = $c['noneWithoutSecure'];
            $oversize = $c['oversize'];

            $unsafeShare = ($noSecure + $noneWithoutSecure) / $set;
            $weakShare = ($noHttpOnly + $noSameSite + $oversize) / ($set * 3.0);
            $score = (int) max(0, min(100, round(100 - 70 * $unsafeShare - 30 * $weakShare)));

            if ($noSecure > 0 || $noneWithoutSecure > 0) {
                $rating = Score::POOR;
            } elseif ($noHttpOnly > 0 || $noSameSite > 0 || $oversize > 0) {
                $rating = Score::NEEDS_WORK;
            } else {
                $rating = Score::GOOD;
            }

            return [
                'score' => $score,
                'rating' => $rating,
                'setCount' => $set,
                'responses' => $c['responses'],
                'noSecure' => $noSecure,
                'noHttpOnly' => $noHttpOnly,
                'noSameSite' => $noSameSite,
                'noneWithoutSecure' => $noneWithoutSecure,
                'oversize' => $oversize,
            ];
        }, null);
    }

    /** A zeroed counter bag. @return array<string,int> */
    private static function zero(): array
    {
        return [
            'setCount' => 0,
            'responses' => 0,
            'noSecure' => 0,
            'noHttpOnly' => 0,
            'noSameSite' => 0,
            'noneWithoutSecure' => 0,
            'oversize' => 0,
        ];
    }

    /**
     * Coerce a stored (possibly corrupt/partial) value into a full counter bag.
     * Corrupt state is treated as absent, never as a reason to throw.
     *
     * @param mixed $cur
     * @return array<string,int>
     */
    private static function normalize($cur): array
    {
        $c = self::zero();
        if (is_array($cur)) {
            foreach ($c as $k => $_) {
                if (isset($cur[$k]) && (is_int($cur[$k]) || is_float($cur[$k]))) {
                    $c[$k] = (int) $cur[$k];
                }
            }
        }

        return $c;
    }

    /** Test seam: fully reset the collector between cases. */
    public static function resetForTests(): void
    {
        Store::delete(self::KEY);
    }
}
