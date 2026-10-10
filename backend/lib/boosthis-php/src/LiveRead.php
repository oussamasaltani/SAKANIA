<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * Live per-install READ helpers — the PHP sibling of the Java runtime's
 * LiveRead.java, the Go runtime's live_read.go, boosthis-py's live_read.py and
 * the Node runtime's liveData.ts.
 *
 * This is the READ side of the two-way channel: it lets the PHP MCP's
 * boosthis.snapshot + boosthis.crash_risk tools return an install's REAL
 * uploaded picture (full perf snapshot + the "what is likely to crash my app"
 * risk feed) instead of the static on-device note — but ONLY when the process
 * has been handed read credentials for one specific install.
 *
 * CREDENTIAL RESOLUTION (mirrors the single-tenant stdio-server contract): an
 * explicit per-call override wins; otherwise env BOOSTHIS_INSTALL_ID +
 * BOOSTHIS_READ_TOKEN win, falling back to the persisted install id + read
 * token minted by Telemetry::enable(). The read token is the strictly
 * read-only credential the developer copies into their AI — NEVER the delete
 * token. With no creds every reader returns null and the tools serve the note.
 *
 * FAIL-OPEN + FAIL-SOFT (like Community): any failure — not configured,
 * kill-switched, offline, non-200, bad JSON, oversized, PII trip — yields null
 * so the caller serves the note; nothing ever throws to the host. A 404 from
 * either endpoint is a SEMANTIC answer meaning "nothing uploaded yet": it is
 * NOT an error, and the caller surfaces the matching NOTE_*_UNAVAILABLE text.
 * Returned rows are re-run through the PII guard (defence in depth) and any row
 * that trips it is dropped rather than thrown. The final whole-response guard
 * pass deliberately EXCLUDES our own shipped constants (the note prose + the
 * rule-id pointers), whose field name / text would otherwise trip the guard.
 *
 * NEVER mutates module state — per-call creds are threaded through
 * resolveLiveCreds() only.
 *
 * PHP DIFFERENCES from the Java sibling (same names, documented here):
 *  - Java resolves the endpoint from BOOSTHIS_INGEST_URL / BOOSTHIS_ENDPOINT
 *    (with BOOSTEN_* fallbacks) then the persisted config's `endpoint`, then
 *    Telemetry.DEFAULT_ENDPOINT. PHP has no persisted `endpoint` and the
 *    canonical base is BOOSTHIS_API_BASE (RuntimeFlags::apiBase()); the same
 *    env override order is honoured, then apiBase() is the default.
 *  - The persisted install id / read token come from Telemetry::installId() and
 *    Telemetry::readToken() (the Store-backed record) rather than a
 *    config.json read.
 */
final class LiveRead
{
    /** ~2MB belt-and-braces cap on any single read (matches the server caps). */
    public const MAX_READ_BYTES = 2000000;

    /** Bounds every live read, in milliseconds. */
    public const LIVE_READ_TIMEOUT_MS = 5000;

    /**
     * Note prose returned alongside a successful live read, and the
     * "unavailable" prose the MCP tools serve when a reader returns null. Kept
     * close to the Java/Go/Python/Node wording so the tool output reads the
     * same across runtimes.
     */
    public const NOTE_SNAPSHOT =
        'Live full snapshot read from your Boosthis server: the whole meter page '
        . 'your app last uploaded (per-route rows, cross-cutting findings, and the '
        . 'responsiveness/budget axes). Numbers + code-defined route labels only — '
        . 'never user values or PII.';

    public const NOTE_SNAPSHOT_UNAVAILABLE =
        'No live snapshot available. This tool reads your app\'s OWN uploaded '
        . 'snapshot from the Boosthis server, which needs read credentials '
        . '(BOOSTHIS_INSTALL_ID + BOOSTHIS_READ_TOKEN, or a registered install '
        . 'with a readToken from Telemetry::enable()). Without them, use the '
        . 'on-device tools (session_summary, recent_samples).';

    public const NOTE_CRASH_RISK =
        'Potential-crashes risk feed read from your Boosthis server: the crash '
        . 'classes your app has ALREADY recorded (uncaught exceptions on the request '
        . 'thread or a background worker), newest-first, joined with the '
        . 'stability summary from your latest snapshot. Each crash class carries '
        . 'relatedRules — fetch them with boosthis.get_rule for the fix. Signatures '
        . 'are code-derived (error name + redacted frame), never the raw error '
        . 'message, so no user value is exposed.';

    public const NOTE_CRASH_RISK_UNAVAILABLE =
        'No live crash-risk feed available. This tool reads your app\'s OWN '
        . 'recorded crash classes from the Boosthis server, which needs read '
        . 'credentials (BOOSTHIS_INSTALL_ID + BOOSTHIS_READ_TOKEN, or a '
        . 'registered install with a readToken from Telemetry::enable()). '
        . 'Without them, use boosthis.list_rules to review the crash-prevention rules.';

    /**
     * The PHP-pack rule ids most likely to prevent a generic uncaught
     * exception (OOM from an unbounded body or result set, cache blow-up,
     * un-diagnosable memory deaths). Deterministic (no server round-trip): the
     * matcher / rule book holds the fix text; this only points the AI at the
     * right rules for each crash class.
     *
     * @var list<string>
     */
    public const DEFAULT_CRASH_RULES = [
        'php-request-body-limit-missing',
        'php-unbounded-in-memory-cache',
        'php-unbounded-result-set',
        'php-memory-limit-unset',
    ];

    /** @var list<string> */
    private const WORKER_CRASH_RULES = [
        'php-worker-unbounded-queue',
        'php-worker-pool-starvation',
        'php-scheduled-task-overlap',
    ];

    /** @var list<string> */
    private const RENDER_CRASH_RULES = [
        'php-large-json-serialize',
        'php-string-concat-in-loop',
        'php-serializer-per-request',
    ];

    /**
     * Maps a crash KIND to the PHP-pack rule ids most likely to prevent it.
     * PHP's handler wrappers record uncaught exceptions; a crash on a spawned
     * background worker ("worker", the analog of Node's "unhandledRejection")
     * points at the worker-lifecycle rules. Unknown kinds fall back to
     * DEFAULT_CRASH_RULES.
     *
     * @return array<string,list<string>>
     */
    public static function crashKindRules(): array
    {
        return [
            'uncaught' => self::DEFAULT_CRASH_RULES,
            'worker' => self::WORKER_CRASH_RULES,
            'unhandledRejection' => self::WORKER_CRASH_RULES,
            'render' => self::RENDER_CRASH_RULES,
        ];
    }

    /**
     * Rules addressing a blocked/overloaded process (the snapshot's stability
     * axis) — lock contention, pool starvation, missing client timeouts —
     * independent of any single crash class.
     *
     * @var list<string>
     */
    public const STABILITY_RULES = [
        'php-blocking-hot-path',
        'php-worker-pool-starvation',
        'php-httpclient-no-timeout',
    ];

    /**
     * The low-level GET seam, swappable so offline tests never touch the
     * network — the mirror of Java's Getter interface. By default it routes
     * through the shared Transmit chokepoint (Transmit::safeGet), so the PII
     * guard on the URL query, BOOSTHIS_DISABLED, and the User-Agent all still
     * apply; a test may swap in a recorder.
     *
     * PHP DIFFERENCE: Java's Getter returns raw status + body bytes and does
     * its own JSON parse in fetchJsonMap(). PHP delegates to Transmit::safeGet,
     * which already decodes the JSON body, so the seam returns
     * ['status' => int, 'body' => array|null].
     *
     * @var (callable(string,string,int):array{status:int,body:array<mixed>|null})|null
     */
    private static $getter = null;

    private function __construct()
    {
    }

    /**
     * Test seam: swap the GET transport (null restores the real one).
     *
     * @param (callable(string,string,int):array{status:int,body:array<mixed>|null})|null $fn
     */
    public static function setGetterForTesting(?callable $fn): void
    {
        self::$getter = $fn;
    }

    /**
     * Package-shared GET. Returns ['status' => int, 'body' => array|null].
     * Never throws — a transport error degrades to status 0.
     *
     * @return array{status:int,body:array<mixed>|null}
     */
    private static function doGet(string $url, string $authToken, int $timeoutMs): array
    {
        return Safe::run(
            static function () use ($url, $authToken, $timeoutMs): array {
                if (self::$getter !== null) {
                    return (self::$getter)($url, $authToken, $timeoutMs);
                }

                return Transmit::safeGet($url, [], $authToken !== '' ? $authToken : null, $timeoutMs);
            },
            ['status' => 0, 'body' => null]
        );
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
     * The per-call read credentials for one install. Used both as the optional
     * override passed into a reader and as the resolved credential.
     */
    public static function makeCreds(string $installId, string $readToken, string $baseUrl = ''): array
    {
        return [
            'installId' => trim($installId),
            'readToken' => trim($readToken),
            'baseUrl' => trim($baseUrl),
        ];
    }

    /**
     * Resolve read credentials for ONE call. Returns null when either the
     * install id or read token is missing. An explicit override wins; otherwise
     * env BOOSTHIS_INSTALL_ID + BOOSTHIS_READ_TOKEN win, falling back to the
     * persisted install id + read token. Never throws.
     *
     * @param array{installId?:string,readToken?:string,baseUrl?:string}|null $override
     * @return array{installId:string,readToken:string,baseUrl:string}|null
     */
    public static function resolveLiveCreds(?array $override): ?array
    {
        if ($override !== null) {
            $id = trim((string) ($override['installId'] ?? ''));
            $tok = trim((string) ($override['readToken'] ?? ''));
            $base = trim((string) ($override['baseUrl'] ?? ''));
            if ($base === '') {
                $base = RuntimeFlags::apiBase();
            }
            if ($id === '' || $tok === '') {
                return null;
            }

            return [
                'installId' => $id,
                'readToken' => $tok,
                'baseUrl' => self::trimRightSlash($base),
            ];
        }

        $id = self::firstEnv('BOOSTHIS_INSTALL_ID', 'BOOSTEN_INSTALL_ID');
        $tok = self::firstEnv('BOOSTHIS_READ_TOKEN', 'BOOSTEN_READ_TOKEN');
        $base = self::firstEnv(
            'BOOSTHIS_INGEST_URL',
            'BOOSTEN_INGEST_URL',
            'BOOSTHIS_ENDPOINT',
            'BOOSTEN_ENDPOINT'
        );
        if ($id === '') {
            $id = (string) (Telemetry::installId() ?? '');
        }
        if ($tok === '') {
            $tok = (string) (Telemetry::readToken() ?? '');
        }
        if ($base === '') {
            // PHP DIFFERENCE: no persisted `endpoint`; the canonical default
            // base is BOOSTHIS_API_BASE via RuntimeFlags::apiBase().
            $base = RuntimeFlags::apiBase();
        }
        if ($id === '' || $tok === '') {
            return null;
        }

        return [
            'installId' => $id,
            'readToken' => $tok,
            'baseUrl' => self::trimRightSlash($base),
        ];
    }

    private static function trimRightSlash(string $s): string
    {
        return rtrim($s, '/');
    }

    /**
     * GET a JSON object from the server with the read-token bearer. Returns
     * null on any failure. Honors the kill-switch and screens the URL query
     * through the PII guard before touching the network. A non-200 (including a
     * semantic 404) yields null so the caller serves the note.
     *
     * @return array<mixed>|null
     */
    private static function fetchJsonMap(string $rawUrl, string $readToken): ?array
    {
        if (RuntimeFlags::disabled()) {
            return null;
        }
        try {
            Transmit::assertNoPiiInUrl($rawUrl);
        } catch (\Throwable $piiTrip) {
            return null;
        }
        $res = self::doGet($rawUrl, $readToken, self::LIVE_READ_TIMEOUT_MS);
        if ((int) $res['status'] !== 200) {
            return null;
        }
        $data = $res['body'];
        if (!is_array($data)) {
            return null;
        }
        // Belt-and-braces size cap on the decoded payload.
        $encoded = Json::encode($data);
        if ($encoded === null || strlen($encoded) === 0 || strlen($encoded) > self::MAX_READ_BYTES) {
            return null;
        }

        return $data;
    }

    /** Coerce a decoded JSON value to a number, 0 for anything else. */
    private static function liveNum(mixed $v): float
    {
        return is_int($v) || is_float($v) ? (float) $v : 0.0;
    }

    /** $v as a string, or "" when it is not a string. */
    private static function liveStr(mixed $v): string
    {
        return is_string($v) ? $v : '';
    }

    /**
     * $v as a string, or null when it is not a string (so firstSeenAt /
     * lastSeenAt serialize as null rather than "").
     */
    private static function liveStrOrNull(mixed $v): ?string
    {
        return is_string($v) ? $v : null;
    }

    /**
     * The whole latest perf snapshot for one install (every meter the in-app
     * dashboard shows), or null so the caller serves the note. The snapshot
     * already passed the upload PII guard; it is re-checked here (defence in
     * depth) and dropped rather than thrown on a trip.
     *
     * @param array{installId?:string,readToken?:string,baseUrl?:string}|null $override
     * @return array<string,mixed>|null
     */
    public static function getLiveSnapshot(?array $override = null): ?array
    {
        return Safe::run(static function () use ($override): ?array {
            $cfg = self::resolveLiveCreds($override);
            if ($cfg === null) {
                return null;
            }
            $snap = self::fetchJsonMap(
                $cfg['baseUrl'] . '/snapshot?installId=' . rawurlencode($cfg['installId']),
                'Bearer ' . $cfg['readToken']
            );
            if ($snap === null) {
                return null;
            }
            if (Pii::checkNoPii($snap) !== null) {
                return null;
            }

            return [
                'available' => true,
                'source' => 'boosthis-server',
                'snapshot' => $snap,
                'note' => self::NOTE_SNAPSHOT,
            ];
        }, null);
    }

    /**
     * The install's already-recorded crash classes + stability summary, with
     * static rule-id pointers, or null so the caller serves the note. Never
     * mutates module state.
     *
     * @param array{installId?:string,readToken?:string,baseUrl?:string}|null $override
     * @return array<string,mixed>|null
     */
    public static function getLiveCrashRisk(?array $override = null): ?array
    {
        return Safe::run(static function () use ($override): ?array {
            $cfg = self::resolveLiveCreds($override);
            if ($cfg === null) {
                return null;
            }
            $data = self::fetchJsonMap(
                $cfg['baseUrl'] . '/crashes/risk?installId=' . rawurlencode($cfg['installId']),
                'Bearer ' . $cfg['readToken']
            );
            if ($data === null) {
                return null;
            }

            $kindRules = self::crashKindRules();
            $crashes = [];
            $crashesIn = $data['crashes'] ?? null;
            if (is_array($crashesIn)) {
                foreach ($crashesIn as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $kind = self::liveStr($item['kind'] ?? null);
                    if ($kind === '') {
                        $kind = 'uncaught';
                    }
                    $related = $kindRules[$kind] ?? self::DEFAULT_CRASH_RULES;
                    $row = [
                        'signature' => self::liveStr($item['signature'] ?? null),
                        'errorName' => self::liveStr($item['errorName'] ?? null),
                        'kind' => $kind,
                        'redactedFrame' => self::liveStr($item['redactedFrame'] ?? null),
                        'countBucket' => self::liveStr($item['countBucket'] ?? null),
                        'occurrences' => self::liveNum($item['occurrences'] ?? null),
                        'firstSeenAt' => self::liveStrOrNull($item['firstSeenAt'] ?? null),
                        'lastSeenAt' => self::liveStrOrNull($item['lastSeenAt'] ?? null),
                        'relatedRules' => $related,
                    ];
                    // Per-row PII re-check (defence in depth) — drop, never throw.
                    if (Pii::checkNoPii($row) === null) {
                        $crashes[] = $row;
                    }
                }
            }

            $stability = null;
            $st = $data['stability'] ?? null;
            if (is_array($st) && Pii::checkNoPii($st) === null) {
                $stability = $st;
            }

            // Defence in depth over the SERVER-SUPPLIED data only. note/source/
            // the rule-id pointers are our own shipped constants, not server
            // data — and the field name "note" itself trips the guard — so the
            // final check excludes them.
            $guarded = ['crashes' => $crashes, 'stability' => $stability];
            if (Pii::checkNoPii($guarded) !== null) {
                return null;
            }

            return [
                'available' => true,
                'source' => 'boosthis-server',
                'crashClasses' => self::liveNum($data['crashClasses'] ?? null),
                'totalOccurrences' => self::liveNum($data['totalOccurrences'] ?? null),
                'crashes' => $crashes,
                'stability' => $stability,
                'stabilityRules' => self::STABILITY_RULES,
                'note' => self::NOTE_CRASH_RISK,
            ];
        }, null);
    }
}
