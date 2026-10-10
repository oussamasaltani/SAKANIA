<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * The {@code accessPressure} additive meter axis (PHP) — the parity sibling of
 * the shared cross-runtime "Access Pressure" axis (see the Java kit's
 * {@see AccessPressure} equivalent and the Go kit's readAccessPressure). The
 * first status-shaped signal on the wire: counts of the app's OWN answers —
 * rejected (401/403/429), not-found (404), server-error (5xx) — plus the peak
 * per-minute rejection burst, the peak per-minute request rate against the
 * session's own median (the surge ratio), and an anonymous estimate of how many
 * distinct clients the rejections came from. Runtime evidence only; NEVER feeds
 * the Speed score; additive and display-only.
 *
 * SOURCE ON PHP — the response path only. The middleware calls
 * {@see recordResponse()} once per finished response with the status code it
 * observes (and, when it has it, the transport peer HOST). No paths, no
 * identifiers ever enter here.
 *
 * ABSOLUTE PRIVACY RULE. No URL, no route, no client address, no header, no user
 * identity ever leaves the app. The client address is hashed with a random
 * per-day salt into ONE of 63 bit positions inside the local scope of
 * {@see recordResponse()} (the same windowed-OR-sketch design as issue reach)
 * and is retained NOWHERE — only the sketch's popcount ever crosses the wire.
 *
 * PHP lifecycle note. PHP has no long-lived process, so every counter, the
 * minute ring, the running peaks, the reach sketch AND the per-day salt live in
 * {@see Store}, updated on the WRITE path only through one locked read-modify-
 * write per response. Rolling the minute buckets on the write path (never on
 * read) is the shared trap the doctrine calls out: rolling on read against the
 * wall clock files phantom quiet minutes when the clock is pinned, collapsing
 * the baseline median to 0 so everything looks like a surge.
 *
 * BANDING ON SHAPE. Banding keys on the SHAPE of traffic — share of rejections,
 * burst against the session's own quiet baseline — never raw counts, because a
 * login page always has failed logins and a public API always has 404s. Where
 * there is no baseline yet the axis is honestly ABSENT.
 *
 * Honest absence / warming gate. {@see read()} returns {@code null} until at
 * least {@value self::AP_MIN_TOTAL_FOR_RATING} responses have been observed AND
 * at least {@value self::AP_MIN_BASELINE_MINUTES} minute buckets have completed
 * — i.e. 10 minutes AND 50 responses. The dashboard renders the shared warming
 * caption for it; the fixed wording is exposed as {@see WARMING_CAPTION}.
 *
 * Shared wire shape (byte-identical semantics in the Node, Python, Go, Java and
 * PHP kits): {@code { score, rating, unauth, forbidden, limited, notFound,
 * serverError, total, burstPeak60s, surgeRatio, reachEstimate, caption }}.
 */
final class AccessPressure
{
    private function __construct()
    {
    }

    /** The axis key, shared with the other server kits. */
    public const AXIS = 'accessPressure';

    /** Store key for the accumulated state (per-project scope). */
    private const KEY = 'access_pressure';

    /** Exact warming caption the kit renders while the gate is unmet. */
    public const WARMING_CAPTION = 'warming up · needs 10 min + 50 responses';

    /** Length of one rate bucket (the "60-second window" of the design). */
    public const AP_BUCKET_MS = 60000;
    /** Completed minute buckets kept for the baseline median (4 hours). */
    public const AP_MAX_MINUTES = 240;
    /** Responses required before the shape of traffic is worth rating. */
    public const AP_MIN_TOTAL_FOR_RATING = 50;
    /** Completed minutes required before the session has a baseline. */
    public const AP_MIN_BASELINE_MINUTES = 10;
    /**
     * A "surge" below this many requests in its peak minute is noise, not a
     * surge — a quiet dev app getting 20 hits must never band on ratio alone.
     */
    public const AP_SURGE_MIN_PEAK = 30;
    /** Surge-ratio bands (peak minute vs session median minute). */
    public const AP_SURGE_WARN = 3.0;
    public const AP_SURGE_POOR = 8.0;
    /** Rejection-share bands (share of ALL responses answered 401/403/429/404). */
    public const AP_SHARE_WARN = 0.2;
    public const AP_SHARE_POOR = 0.5;
    /** Ratio ceiling so a zero-median session cannot print absurd numbers. */
    public const AP_SURGE_CAP = 999.0;
    /** Counter saturation ceiling — counts stop climbing rather than overflow. */
    public const AP_COUNT_MAX = 1000000000;

    /**
     * Clock indirection — tests pin it for deterministic bucket rollover.
     *
     * @var (callable():int)|null
     */
    private static $nowFn = null;

    /**
     * UTC calendar-date source, overridable in tests so salt rotation is
     * deterministic. Returns "YYYY-MM-DD".
     *
     * @var (callable():string)|null
     */
    private static $todayFn = null;

    private static function now(): int
    {
        $fn = self::$nowFn;

        return $fn !== null ? (int) $fn() : Telemetry::nowMs();
    }

    private static function today(): string
    {
        $fn = self::$todayFn;

        return $fn !== null ? (string) $fn() : gmdate('Y-m-d');
    }

    private static function bump(int $n): int
    {
        return $n >= self::AP_COUNT_MAX ? self::AP_COUNT_MAX : $n + 1;
    }

    /**
     * Fed by the request finalizer ONCE per finished response with the final
     * status and the transport-level peer address. The address is used ONLY
     * inside this call's stack to flip one anonymous sketch bit — it is never
     * stored, never logged, never placed in any field or caption. Fully
     * self-guarded; no-op under the kill-switch. Never throws. Buckets roll on
     * this WRITE path only, never on read.
     *
     * @param int         $status     the final HTTP status (100..599; else ignored)
     * @param string|null $clientAddr the transport peer HOST ONLY (never a port)
     */
    public static function recordResponse(
        int $status,
        ?string $clientAddr = null,
        ?bool $routeMatched = null
    ): void
    {
        if (RuntimeFlags::disabled()) {
            return;
        }
        // Ignore anything that is not a valid HTTP status. Junk never counts.
        if ($status < 100 || $status > 599) {
            return;
        }
        Safe::fire(static function () use ($status, $clientAddr, $routeMatched): void {
            $now = self::now();
            $today = self::today();
            Store::update(self::KEY, static function ($cur) use ($status, $clientAddr, $routeMatched, $now, $today) {
                $s = self::normalize($cur);
                self::rollBuckets($s, $now);
                $s['total'] = self::bump($s['total']);
                $s['curReq']++;
                if ($s['curReq'] > $s['peakReq']) {
                    $s['peakReq'] = $s['curReq'];
                }
                // Route matching is three-state. Unknown hosts keep the old
                // pressure behaviour, while the uncertainty is published.
                $chosenByApp = $routeMatched === true;
                $cannotTell = $routeMatched === null;
                $rejection = false;
                if ($status === 401) {
                    $s['unauth'] = self::bump($s['unauth']);
                    $rejection = true;
                } elseif ($status === 403) {
                    $s['forbidden'] = self::bump($s['forbidden']);
                    $rejection = !$chosenByApp;
                } elseif ($status === 429) {
                    $s['limited'] = self::bump($s['limited']);
                    $rejection = true;
                } elseif ($status === 404) {
                    $s['notFound'] = self::bump($s['notFound']);
                    $rejection = !$chosenByApp;
                } elseif ($status >= 500) {
                    $s['serverError'] = self::bump($s['serverError']);
                }
                if (($status === 403 || $status === 404) && $chosenByApp) {
                    $s['protocolAnswers'] = self::bump($s['protocolAnswers']);
                }
                if ($rejection && $cannotTell && ($status === 403 || $status === 404)) {
                    $s['unattributedRefusals'] = self::bump($s['unattributedRefusals']);
                }
                if (!$rejection) {
                    return $s;
                }
                $s['curRej']++;
                if ($s['curRej'] > $s['peakRej']) {
                    $s['peakRej'] = $s['curRej'];
                }
                if ($clientAddr !== null && $clientAddr !== '') {
                    // sha256(dailySalt + address) → one of 63 bit positions. The
                    // digest's first 32-bit big-endian word mod 63 mirrors the
                    // issue-reach sketch exactly. Bits 0..62 only, so a signed
                    // 64-bit consumer never sees overflow.
                    $salt = self::dailySalt($s, $today);
                    $digest = hash('sha256', $salt . $clientAddr, true);
                    $word = (ord($digest[0]) << 24)
                        | (ord($digest[1]) << 16)
                        | (ord($digest[2]) << 8)
                        | ord($digest[3]);
                    $pos = $word % 63;
                    // The sketch is stored as a 63-bit integer. PHP integers are
                    // 64-bit on every supported platform, so 1<<62 max is safe.
                    $s['sketch'] = $s['sketch'] | (1 << $pos);
                }

                return $s;
            }, self::zero());
        });
    }

    /**
     * Roll the open minute bucket forward to {@code now}, filing completed
     * minutes (including empty ones — a quiet minute IS the baseline) into the
     * ring capped at AP_MAX_MINUTES. Mutates $s in place.
     *
     * @param array<string,mixed> $s
     */
    private static function rollBuckets(array &$s, int $now): void
    {
        if ($s['bucketStart'] === 0) {
            $s['bucketStart'] = $now;

            return;
        }
        $gap = intdiv($now - $s['bucketStart'], self::AP_BUCKET_MS);
        if ($gap <= 0) {
            return;
        }
        // File the minute that just closed, then any fully-empty minutes between.
        $s['reqMinutes'][] = $s['curReq'];
        $s['curReq'] = 0;
        $s['curRej'] = 0;
        $zeros = min($gap - 1, self::AP_MAX_MINUTES);
        for ($i = 0; $i < $zeros; $i++) {
            $s['reqMinutes'][] = 0;
        }
        $count = count($s['reqMinutes']);
        if ($count > self::AP_MAX_MINUTES) {
            $s['reqMinutes'] = array_slice($s['reqMinutes'], $count - self::AP_MAX_MINUTES);
        }
        $s['bucketStart'] += $gap * self::AP_BUCKET_MS;
    }

    /**
     * Per-UTC-day random salt for the client-address hash. Never derived from
     * anything, never uploaded — rotating it daily means the sketch counts
     * device-DAYS, exactly like the issue-reach design. Persisted alongside the
     * state (PHP has no process to hold it in) so all workers on a given day
     * mix into the SAME 63-bit space; a per-request salt would make one client's
     * bit land differently on each request and saturate reach at 63. Mutates $s.
     *
     * @param array<string,mixed> $s
     */
    private static function dailySalt(array &$s, string $today): string
    {
        if ($s['saltDay'] !== $today || $s['salt'] === '') {
            $s['saltDay'] = $today;
            $raw = Safe::run(static fn (): string => random_bytes(16), '');
            if ($raw === '' || strlen($raw) !== 16) {
                // CSPRNG failure is effectively impossible; a time-mixed fallback
                // still gives a non-persisted, per-day-rotated salt. The salt is
                // only a mixing value for an ANONYMOUS bit position, never a
                // secret.
                $raw = pack('NNNN', mt_rand(), mt_rand(), mt_rand(), mt_rand());
            }
            $s['salt'] = bin2hex($raw);
        }

        return (string) $s['salt'];
    }

    /**
     * Median of the completed minute buckets (zeros included — quiet is real).
     *
     * @param list<int> $minutes
     */
    private static function median(array $minutes): float
    {
        $n = count($minutes);
        if ($n === 0) {
            return 0.0;
        }
        $sorted = $minutes;
        sort($sorted);
        if ($n % 2 === 1) {
            return (float) $sorted[intdiv($n - 1, 2)];
        }

        return ($sorted[intdiv($n, 2) - 1] + $sorted[intdiv($n, 2)]) / 2.0;
    }

    /** Popcount of the 63-bit reach sketch. */
    private static function reachEstimate(int $sketch): int
    {
        $count = 0;
        while ($sketch !== 0) {
            $count += $sketch & 1;
            // Logical right shift by masking is unnecessary: the sketch only ever
            // has bits 0..62 set, so an arithmetic shift never introduces a sign
            // bit here.
            $sketch >>= 1;
        }

        return $count;
    }

    private static function r1(float $n): float
    {
        return round($n * 10) / 10.0;
    }

    private static function r0(float $n): int
    {
        return (int) round($n);
    }

    /**
     * The {@code accessPressure} axis as a closed map, or {@code null} while the
     * session has no baseline yet — a rating with nothing to compare against
     * would be a guess, and a red tile on a healthy busy app is worse than no
     * tile. Buckets roll on the WRITE path only, never here. The ONLY string
     * fields are {@code rating} and {@code caption}; every other field is a
     * number. No address, route, header or user identity ever appears.
     *
     * @return array<string,int|float|string>|null
     */
    public static function read(): ?array
    {
        if (RuntimeFlags::disabled()) {
            return null;
        }

        return Safe::run(static function (): ?array {
            $s = self::normalize(Store::get(self::KEY, null));
            $total = $s['total'];
            if ($total <= 0) {
                return null;
            }
            // Warming gate: 50 responses AND 10 completed minute buckets.
            if ($total < self::AP_MIN_TOTAL_FOR_RATING) {
                return null;
            }
            if (count($s['reqMinutes']) < self::AP_MIN_BASELINE_MINUTES) {
                return null;
            }

            $unauth = $s['unauth'];
            $forbidden = $s['forbidden'];
            $limited = $s['limited'];
            $notFound = $s['notFound'];
            $serverError = $s['serverError'];
            $protocolAnswers = $s['protocolAnswers'];
            $unattributed = $s['unattributedRefusals'];
            $peakReq = $s['peakReq'];
            $peakRej = $s['peakRej'];
            $median = self::median($s['reqMinutes']);
            $reach = self::reachEstimate($s['sketch']);

            // rejections = 401+403+429+404 (serverError rides the wire but NEVER
            // feeds the share — the app's own errors are covered elsewhere).
            $rejections = $unauth + $forbidden + $limited + $notFound - $protocolAnswers;
            $rejShare = $rejections / $total;
            $surgeRatio = self::r1(min(self::AP_SURGE_CAP, $peakReq / max($median, 1.0)));

            // Banding on SHAPE only: share of rejections, and the peak minute
            // against the session's own median — never raw counts. The surge
            // bands only apply once the peak minute carries real volume.
            $surging = $peakReq >= self::AP_SURGE_MIN_PEAK;
            if ($rejShare >= self::AP_SHARE_POOR || ($surging && $surgeRatio >= self::AP_SURGE_POOR)) {
                $rating = Score::POOR;
            } elseif ($rejShare >= self::AP_SHARE_WARN || ($surging && $surgeRatio >= self::AP_SURGE_WARN)) {
                $rating = Score::NEEDS_WORK;
            } else {
                $rating = Score::GOOD;
            }
            $surgePenalty = $surging
                ? min(1.0, max(0.0, $surgeRatio - 1) / (self::AP_SURGE_POOR - 1))
                : 0.0;
            $score = (int) max(0, min(100, self::r0(
                100 - 70 * min(1.0, $rejShare / self::AP_SHARE_POOR) - 30 * $surgePenalty
            )));

            $caption = self::caption(
                $total,
                $rejections,
                $rejShare,
                $peakRej,
                $reach,
                $surging,
                $surgeRatio,
                $protocolAnswers,
                $unattributed
            );

            return [
                'score' => $score,
                'rating' => $rating,
                'unauth' => $unauth,
                'forbidden' => $forbidden,
                'limited' => $limited,
                'notFound' => $notFound,
                'serverError' => $serverError,
                'protocolAnswers' => $protocolAnswers,
                'unattributedRefusals' => $unattributed,
                'breakInRefusals' => $rejections,
                'total' => $total,
                'burstPeak60s' => $peakRej,
                'surgeRatio' => $surgeRatio,
                'reachEstimate' => $reach,
                'caption' => $caption,
            ];
        }, null);
    }

    /**
     * Build the numeric caption: COUNTS, SHARES and RATIOS only — never an
     * address, route, header or user identity. Uses the ≈1 / ≈2–9 / ≈10+
     * "sources" buckets, mirroring the other kits.
     */
    private static function caption(
        int $total,
        int $rejections,
        float $rejShare,
        int $peakRej,
        int $reach,
        bool $surging,
        float $surgeRatio,
        int $protocolAnswers,
        int $unattributed
    ): string {
        $parts = [];
        if ($rejections > 0) {
            $parts[] = $rejections . ' of ' . $total
                . " responses were refusals this app's own routing did not choose ("
                . self::r0($rejShare * 100) . '%)';
            if ($peakRej > 0) {
                $parts[] = 'peak ' . $peakRej . ' rejections/min';
            }
            if ($reach > 0) {
                if ($reach >= 10) {
                    $parts[] = "\u{2248}10+ sources";
                } elseif ($reach >= 2) {
                    $parts[] = "\u{2248}2\u{2013}9 sources";
                } else {
                    $parts[] = "\u{2248}1 source";
                }
            }
        }
        if ($protocolAnswers > 0) {
            $parts[] = $protocolAnswers
                . " more were answers from routes this app serves \u{2014} not counted as pressure";
        }
        if ($unattributed > 0) {
            $parts[] = $unattributed
                . ' could not be attributed to a route on this host and are counted as pressure';
        }
        if ($surging && $surgeRatio >= self::AP_SURGE_WARN) {
            $parts[] = 'traffic peaked at ' . self::numStr($surgeRatio)
                . "\u{00d7} the quiet baseline";
        }
        if ($parts === []) {
            return 'no rejection pressure across ' . $total . ' responses';
        }

        return implode(', ', $parts);
    }

    /** Trim a ratio to a compact string ("3" not "3.0", "3.5" kept). */
    private static function numStr(float $n): string
    {
        if ($n === floor($n)) {
            return (string) (int) $n;
        }

        return (string) $n;
    }

    /** A zeroed state bag. @return array<string,mixed> */
    private static function zero(): array
    {
        return [
            'total' => 0,
            'unauth' => 0,
            'forbidden' => 0,
            'limited' => 0,
            'notFound' => 0,
            'serverError' => 0,
            'protocolAnswers' => 0,
            'unattributedRefusals' => 0,
            'bucketStart' => 0,
            'curReq' => 0,
            'curRej' => 0,
            'reqMinutes' => [],
            'peakReq' => 0,
            'peakRej' => 0,
            'sketch' => 0,
            'saltDay' => '',
            'salt' => '',
        ];
    }

    /**
     * Coerce a stored (possibly corrupt/partial) value into a full state bag.
     * Corrupt state is treated as absent, never as a reason to throw.
     *
     * @param mixed $cur
     * @return array<string,mixed>
     */
    private static function normalize($cur): array
    {
        $s = self::zero();
        if (!is_array($cur)) {
            return $s;
        }
        foreach (['total', 'unauth', 'forbidden', 'limited', 'notFound', 'serverError',
            'protocolAnswers', 'unattributedRefusals',
            'bucketStart', 'curReq', 'curRej', 'peakReq', 'peakRej', 'sketch'] as $k) {
            if (isset($cur[$k]) && (is_int($cur[$k]) || is_float($cur[$k]))) {
                $s[$k] = (int) $cur[$k];
            }
        }
        if (isset($cur['reqMinutes']) && is_array($cur['reqMinutes'])) {
            $mins = [];
            foreach ($cur['reqMinutes'] as $m) {
                if (is_int($m) || is_float($m)) {
                    $mins[] = (int) $m;
                }
            }
            $s['reqMinutes'] = $mins;
        }
        if (isset($cur['saltDay']) && is_string($cur['saltDay'])) {
            $s['saltDay'] = $cur['saltDay'];
        }
        if (isset($cur['salt']) && is_string($cur['salt'])) {
            $s['salt'] = $cur['salt'];
        }

        return $s;
    }

    // ── Test seams (kit convention: *ForTests) ────────────────────────────────

    /** Test seam — pin the collector clock (null restores the wall clock). */
    public static function setClockForTests(?callable $fn): void
    {
        self::$nowFn = $fn;
    }

    /** Test seam — pin the UTC calendar date used for salt rotation. */
    public static function setTodayForTests(?callable $fn): void
    {
        self::$todayFn = $fn;
    }

    /** Test seam — roll the minute buckets forward without waiting a minute. */
    public static function rollBucketsForTests(int $now): void
    {
        Store::update(self::KEY, static function ($cur) use ($now) {
            $s = self::normalize($cur);
            self::rollBuckets($s, $now);

            return $s;
        }, self::zero());
    }

    /** Test seam — set the bucket clock origin. */
    public static function setBucketStartForTests(int $ts): void
    {
        Store::update(self::KEY, static function ($cur) use ($ts) {
            $s = self::normalize($cur);
            $s['bucketStart'] = $ts;

            return $s;
        }, self::zero());
    }

    /** Test seam — drop all access-pressure state and restore the real clock. */
    public static function resetForTests(): void
    {
        Store::delete(self::KEY);
        self::$nowFn = null;
        self::$todayFn = null;
    }
}
