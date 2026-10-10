<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * Registration, consent and the two gates.
 *
 * GATE 1 — entitlement (KillSwitch). Checked first, default CLOSED: a project
 * that has never been activated measures nothing and shows nothing.
 * GATE 2 — telemetry mode. After registration the crashes/issues channel is
 * always on for a registered project; the screen-bearing channels (spans and
 * perf snapshots) stay off unless the owner turned full telemetry on.
 *
 * BOOSTHIS_DISABLED outranks both: no network, no state, no meters.
 *
 * Credentials: consent mints a deleteToken (write/forget) and a readToken
 * (read). The deleteToken MUST be persisted — a kit that forgets it goes dark
 * on the next restart and can never speak for this install again. The rotation
 * guard keys on the delete token alone; a read token is not proof of identity.
 */
final class Telemetry
{
    public const RUNTIME = 'php';

    private const KEY_INSTALL = 'install';
    // Epoch-ms stamp of the last MEASUREMENT upload the server accepted (2xx) —
    // samples, spans, snapshots or crashes. Persisted (not a per-process static)
    // because PHP has no long-lived process: the status page is served in a
    // different request from the one that uploaded, so an in-process flag would
    // always read null there. Consent/registration NEVER writes this: a
    // handshake is not data arriving, and the status page must not imply it was.
    private const KEY_LAST_UPLOAD = 'last_upload';
    /** PHP requests do not share process memory, so this status mirror follows
     * KEY_LAST_UPLOAD into the same local Store. It is never uploaded. */
    private const KEY_UPLOAD_FAILURE = 'upload_failure';
    // Cumulative "rows the server accepted the batch for and then refused",
    // for the life of this install — the size of the hole in the dashboard, so
    // a later clean upload does not erase it. Persisted (not a per-process
    // static) for the same reason KEY_LAST_UPLOAD is: the status page and panel
    // are served in a different request from the one that uploaded, so an
    // in-process count would always read zero there. Shape: {total:int,
    // causes:list<string>} where causes are the KNOWN cause keys, deduped, in
    // the fixed cause order.
    private const KEY_DROPPED = 'dropped_rows';
    private const KEY_CONSENT_TRY = 'consent_attempt';
    private const CONSENT_RETRY_MS = 3600000; // lazy retry, at most once an hour
    // Cadence for re-asking the server what the dashboard "Full telemetry"
    // switch currently says, for an install that is ALREADY registered. PHP has
    // no long-lived process to hang a "once per launch" latch on — every request
    // is a fresh interpreter — so the cap has to be a DURABLE timestamp shared
    // across requests. A per-process flag would mean one extra consent
    // round-trip on EVERY request, which is exactly the storm to avoid.
    private const KEY_REFRESH_AT = 'consent_refresh';
    private const REFRESH_INTERVAL_MS = 3600000; // re-ask at most once an hour

    // The server rejects a bad install id with this fixed machine-readable body.
    private const INVALID_INSTALL_ID_MARKER = 'invalid_install_id';
    // The server's answer when this install id is already registered to someone
    // who holds the credentials and this copy does not. It rides a 200, so it
    // can only be recognised by this marker in the body.
    private const ORPHAN_INSTALL_MARKER = 'already_registered_no_token';

    private static bool $enabled = false;
    private static ?string $installId = null;
    private static ?string $appName = null;
    private static bool $explicitFullTelemetry = false;
    private static bool $invalidInstallId = false;
    private static bool $invalidInstallIdWarned = false;
    private static bool $inactiveWarned = false;
    private static ?string $inactiveNotice = null;
    private static bool $projectKeyOverrideWarned = false;
    private static bool $identityPersistenceWarned = false;
    private static bool $identityPinned = false;
    private static bool $identityRestored = false;
    /** @var array{key:?string,source:string,overrodeShared:bool,display:?string}|null */
    private static ?array $projectKey = null;
    /** Per-cause warn-once latch, same style as $inactiveWarned: the one-shot
     *  "some of what this app sent was dropped" line fires at most once per
     *  process per cause. Keyed by cause key, value always true. */
    private static array $droppedWarned = [];

    private function __construct()
    {
    }

    /**
     * Turn the kit on. Safe to call on every request — the expensive parts are
     * gated on stored state, so the second and later calls are cheap.
     *
     * @param array{installId?:string,appName?:string,projectKey?:string,shareMeterWithAI?:bool} $options
     */
    public static function enable(array $options = []): bool
    {
        if (RuntimeFlags::disabled()) {
            return false;
        }

        return (bool) Safe::run(static function () use ($options): bool {
            // Install identity: explicit > env > persisted > freshly minted.
            // App name keeps its existing explicit > persisted > env > derived order.
            //
            // THE STORE IS NOT READ UNTIL THE PINNED RUNGS HAVE HAD THEIR TURN.
            // The ladder below always preferred a configured id, but the
            // restored-from-disk flag used to be computed at the top, which
            // put a read of the persisted id ABOVE the environment rung in the
            // only order anyone can actually check — the order the code is
            // written in. That is how a kit ends up ignoring a pinned id on
            // every host that has already run once, so the flag is computed
            // where the store is genuinely consulted instead. It describes
            // what is on disk, not which rung won, so nothing about it needs
            // to be early.
            $stored = self::stored();
            $installId = null;
            $identityPinned = false;
            if (array_key_exists('installId', $options)) {
                $candidate = is_string($options['installId']) ? trim($options['installId']) : '';
                if (self::isUuid($candidate)) {
                    $installId = $candidate;
                    $identityPinned = true;
                } else {
                    self::refuseInstallId('the host argument');
                }
            }
            if ($installId === null) {
                $candidate = RuntimeFlags::installId();
                if ($candidate !== null && self::isUuid(trim($candidate))) {
                    $installId = trim($candidate);
                    $identityPinned = true;
                } elseif ($candidate !== null) {
                    self::refuseInstallId('the environment (BOOSTHIS_INSTALL_ID or BOOSTEN_INSTALL_ID)');
                }
            }
            if ($installId === null && array_key_exists('installId', $stored)) {
                $candidate = is_string($stored['installId']) ? trim($stored['installId']) : '';
                if (self::isUuid($candidate)) {
                    $installId = $candidate;
                    // The store won the ladder, so this is the one place the
                    // restored-from-disk answer can be true. APCu-only hosts
                    // answer false here because identityDiskInfo excludes it:
                    // a process-local cache cannot prove a restart saw this id.
                    self::$identityRestored =
                        Store::identityDiskInfo(self::KEY_INSTALL, $candidate)['restored'];
                } else {
                    self::refuseInstallId('persisted local state');
                }
            }
            if ($installId === null) {
                $installId = self::newInstallId();
            }
            // Rotation continues only the identity selected above. It never
            // outranks the ladder and never adopts another origin's rotation.
            $rotationOrigin = null;
            if (array_key_exists('previousInstallId', $stored)) {
                $candidate = is_string($stored['previousInstallId'])
                    ? trim($stored['previousInstallId']) : '';
                if (self::isUuid($candidate)) {
                    $rotationOrigin = $candidate;
                } else {
                    self::refuseInstallId('the stored rotation origin');
                }
            }
            if ($rotationOrigin === $installId && array_key_exists('installId', $stored)) {
                $rotated = is_string($stored['installId']) ? trim($stored['installId']) : '';
                if (self::isUuid($rotated)) {
                    $installId = $rotated;
                } else {
                    self::refuseInstallId('the stored rotation');
                }
            }
            if (!$identityPinned && (($stored['installId'] ?? null) !== $installId)) {
                Store::update(self::KEY_INSTALL, static function ($cur) use ($installId): array {
                    $rec = is_array($cur) ? $cur : [];
                    if (($rec['installId'] ?? null) !== $installId) {
                        unset(
                            $rec['deleteToken'],
                            $rec['readToken'],
                            $rec['registeredAt'],
                            $rec['fullTelemetry'],
                            $rec['disconnected']
                        );
                    }
                    $rec['installId'] = $installId;
                    return $rec;
                }, []);
                $kept = self::stored()['installId'] ?? null;
                if ($kept !== $installId) {
                    self::warnIdentityPersistenceOnce(
                        Store::identityPersistenceCause()
                            ?? 'the saved identity could not be kept on disk'
                    );
                }
            }
            $appName = $options['appName']
                ?? ($stored['appName'] ?? null)
                ?? RuntimeFlags::appName()
                ?? BuildIdentity::detectAppName();
            if (is_string($appName)) {
                // The server REJECTS anything longer, so clamp client-side.
                $appName = substr(trim($appName), 0, 60);
                if ($appName === '') {
                    $appName = null;
                }
            }

            self::$installId = $installId;
            self::$identityPinned = $identityPinned;
            self::$appName = $appName;
            self::$enabled = true;
            Candidates::start();
            self::$explicitFullTelemetry = (bool) ($options['shareMeterWithAI'] ?? false);
            // Restore the project name/code beside the credentials, so every
            // surface names the project before this request's refresh returns.
            ProjectIdentity::restore($installId);

            // Resolve once for this enabled runtime. Every later registration
            // refresh and key-gated feature reads this same answer.
            $resolvedKey = self::$projectKey ?? ProjectKey::resolve($options['projectKey'] ?? null);
            if (self::$projectKey === null) {
                self::$projectKey = $resolvedKey;
                self::warnProjectKeyOverrideOnce($resolvedKey);
            }

            $explicit = isset($options['installId']) || isset($options['appName'])
                || isset($options['projectKey']) || isset($options['shareMeterWithAI']);
            self::ensureRegistered($resolvedKey['key'], $explicit);

            return true;
        }, false);
    }

    public static function isEnabled(): bool
    {
        return self::$enabled && !RuntimeFlags::disabled();
    }

    public static function installId(): ?string
    {
        return self::$installId ?? (self::stored()['installId'] ?? null);
    }

    public static function appName(): ?string
    {
        return self::$appName ?? (self::stored()['appName'] ?? null);
    }

    /** The key resolved at enable time, or the environment-only answer before enable. */
    public static function projectKey(): ?string
    {
        return self::projectKeyDetails()['key'];
    }

    /**
     * @return array{key:?string,source:string,overrodeShared:bool,display:?string}
     */
    public static function projectKeyDetails(): array
    {
        return self::$projectKey ?? ProjectKey::resolve();
    }

    /**
     * The whole persisted registration record.
     *
     * @return array<string,mixed>
     */
    public static function stored(): array
    {
        $v = Store::get(self::KEY_INSTALL, []);

        return is_array($v) ? $v : [];
    }

    public static function deleteToken(): ?string
    {
        $stored = self::stored();
        if (self::$installId !== null && ($stored['installId'] ?? null) !== self::$installId) {
            return null;
        }
        $v = $stored['deleteToken'] ?? null;

        return is_string($v) && $v !== '' ? $v : null;
    }

    public static function readToken(): ?string
    {
        $stored = self::stored();
        if (self::$installId !== null && ($stored['installId'] ?? null) !== self::$installId) {
            return null;
        }
        $v = $stored['readToken'] ?? null;

        return is_string($v) && $v !== '' ? $v : null;
    }

    /** True once the server has minted credentials for this install. */
    public static function isRegistered(): bool
    {
        return self::deleteToken() !== null;
    }

    /**
     * True once the server has told us this install id is malformed. Surfaced
     * so the bubble can show it; it does NOT arm the consent retry.
     */
    public static function invalidInstallId(): bool
    {
        return self::$invalidInstallId;
    }

    public static function inactiveNotice(): ?string
    {
        return self::$inactiveNotice;
    }

    /**
     * GATE 2, screen-bearing side. Spans and perf snapshots only move when the
     * owner turned full telemetry on — either in the dashboard (the server says
     * so in the consent response) or explicitly in code.
     */
    public static function fullTelemetry(): bool
    {
        if (self::$explicitFullTelemetry) {
            return true;
        }

        return (bool) (self::stored()['fullTelemetry'] ?? false);
    }

    /**
     * GATE 2, issues side. Crash and issue reporting is on for any registered
     * project — that is the part every project gets.
     */
    public static function issuesEnabled(): bool
    {
        return self::isEnabled() && self::isRegistered() && !self::disconnected();
    }

    public static function disconnected(): bool
    {
        return (bool) (self::stored()['disconnected'] ?? false);
    }

    /** Milliseconds since this install first registered. Null before registration. */
    public static function ageMs(): ?int
    {
        $at = self::stored()['registeredAt'] ?? null;
        if (!is_int($at) && !is_float($at)) {
            return null;
        }

        return (int) max(0, self::nowMs() - (int) $at);
    }

    /**
     * Stamp "the server just accepted a measurement upload". Called ONLY at the
     * 2xx acceptance points of the measurement channels — samples, spans,
     * snapshots and crashes — never on consent/registration. Best-effort: a
     * failure here must never destabilise an upload path.
     */
    public static function noteUploadAccepted(): void
    {
        Safe::fire(static function (): void {
            Store::set(self::KEY_LAST_UPLOAD, self::nowMs());
            Store::update(self::KEY_UPLOAD_FAILURE, static function ($cur): array {
                $v = is_array($cur) ? $cur : [];
                $v['lastAttemptFailed'] = false;
                return $v;
            });
        });
    }

    /** Record a whole upload batch that did not land. Never throws. */
    public static function noteUploadRejected(string $reason): void
    {
        Safe::fire(static function () use ($reason): void {
            if (!in_array($reason, ['unauthorized', 'rejected', 'server-error', 'unreachable'], true)) {
                return;
            }
            Store::update(self::KEY_UPLOAD_FAILURE, static function ($cur) use ($reason): array {
                $v = is_array($cur) ? $cur : [];
                return [
                    'at' => self::nowMs(),
                    'reason' => $reason,
                    'count' => max(0, (int) ($v['count'] ?? 0)) + 1,
                    'lastAttemptFailed' => true,
                ];
            });
        });
    }

    /** @return array{at:int,reason:string}|null */
    public static function lastUploadFailure(): ?array
    {
        return Safe::run(static function (): ?array {
            $v = Store::get(self::KEY_UPLOAD_FAILURE, null);
            if (!is_array($v) || !is_numeric($v['at'] ?? null)
                || !is_string($v['reason'] ?? null)) {
                return null;
            }
            return ['at' => (int) $v['at'], 'reason' => $v['reason']];
        }, null);
    }

    public static function droppedUploadCount(): int
    {
        return (int) Safe::run(static function (): int {
            $v = Store::get(self::KEY_UPLOAD_FAILURE, null);
            return is_array($v) ? max(0, (int) ($v['count'] ?? 0)) : 0;
        }, 0);
    }

    public static function isLastUploadAttemptFailed(): bool
    {
        return (bool) Safe::run(static function (): bool {
            $v = Store::get(self::KEY_UPLOAD_FAILURE, null);
            return is_array($v) && ($v['lastAttemptFailed'] ?? false) === true;
        }, false);
    }

    /**
     * Epoch-ms of the last accepted measurement upload, or null when this
     * install has never had one accepted. Read by the standalone status page.
     * Fail-safe: any error degrades to null (nothing uploaded yet).
     */
    public static function lastUploadAt(): ?int
    {
        return Safe::run(static function (): ?int {
            $v = Store::get(self::KEY_LAST_UPLOAD, null);

            return (is_int($v) || is_float($v)) ? (int) $v : null;
        }, null);
    }

    // ── "The server stored fewer rows than this app sent" ──────────────────
    //
    // WHY THIS EXISTS. The two upload endpoints answer 202 and then discard
    // individual rows they cannot store: a route label the shared privacy guard
    // refuses, a span past the per-trace cap, a snapshot entry whose name is
    // rejected. Until now the kit read that 202 as unqualified success, so a
    // developer whose every route label was being thrown away saw a healthy
    // app, no output, and a dashboard quietly missing data. This reads the
    // honesty fields off the reply the server already sent back, warns ONCE per
    // cause, and keeps a cumulative count for the in-app surfaces — in the same
    // words the project's web page uses, so the two never tell a different
    // story. Nothing here is uploaded: the warning is local output only, and
    // absence of the fields (an older server, a non-JSON body) means "nothing
    // to report" — byte-identical behaviour to before.

    /** The closed set of causes this kit knows, in the fixed order used
     *  everywhere the causes are listed. A cause key outside this set is
     *  counted in the total and named nowhere — the kit never invents an
     *  explanation for something it cannot read. */
    private const DROP_CAUSE_KEYS = [
        'labelRejected',
        'traceCapReached',
        'snapshotEntryFiltered',
    ];

    /** Short cause text — the same words the project's web page uses. */
    private const DROP_CAUSE_TEXT = [
        'labelRejected' => 'route names the privacy guard refused',
        'traceCapReached' => 'spans past the 20-span limit',
        'snapshotEntryFiltered' => 'snapshot entries the privacy guard refused',
    ];

    /** What the developer changes to stop it. Second sentence of the warning.
     *  The dash in the traceCapReached fix is an em dash (U+2014). */
    private const DROP_CAUSE_FIX = [
        'labelRejected' => 'Name routes in code, the way GET /orders is written.',
        'traceCapReached' => "A trace keeps its first 20 spans \u{2014} measure fewer steps per request, or split a very long trace.",
        'snapshotEntryFiltered' => 'Name screens in code, never from what a person typed or an id.',
    ];

    /**
     * Record what one upload reply's body said about dropped rows, and warn
     * once per cause. Called from the SAME 2xx acceptance points as
     * noteUploadAccepted, with the body the transport already decoded — off the
     * request path, and self-guarded so a malformed reply can never fail an
     * upload, let alone reach the host.
     *
     * A body without a positive `dropped` (an older server, a non-array body)
     * changes no state and prints nothing.
     *
     * @param array<mixed>|null $body
     */
    public static function noteServerDrops(?array $body): void
    {
        Safe::fire(static function () use ($body): void {
            if (!is_array($body)) {
                return;
            }
            $total = self::finiteCount($body['dropped'] ?? null);
            if ($total <= 0) {
                return;
            }

            // Per-cause counts for the causes this kit knows, in fixed order.
            $byCause = [];
            $raw = $body['droppedByCause'] ?? null;
            if (is_array($raw)) {
                foreach (self::DROP_CAUSE_KEYS as $cause) {
                    $n = self::finiteCount($raw[$cause] ?? null);
                    if ($n > 0) {
                        $byCause[$cause] = $n;
                    }
                }
            }

            // Fold the total + newly seen causes into the persisted running
            // tally, so the status page and panel (served in another request)
            // can show the size of the hole.
            Store::update(self::KEY_DROPPED, static function ($cur) use ($total, $byCause) {
                $rec = is_array($cur) ? $cur : [];
                $prevTotal = self::finiteCount($rec['total'] ?? null);
                $seen = [];
                if (isset($rec['causes']) && is_array($rec['causes'])) {
                    foreach ($rec['causes'] as $c) {
                        if (is_string($c)) {
                            $seen[$c] = true;
                        }
                    }
                }
                foreach ($byCause as $cause => $_n) {
                    $seen[$cause] = true;
                }
                // Keep the causes in the fixed order regardless of arrival order.
                $ordered = [];
                foreach (self::DROP_CAUSE_KEYS as $cause) {
                    if (isset($seen[$cause])) {
                        $ordered[] = $cause;
                    }
                }

                return ['total' => $prevTotal + $total, 'causes' => $ordered];
            }, []);

            // One line per process per cause, on the error stream, ungated —
            // the same latch style as warnStayedInactiveOnce.
            foreach ($byCause as $cause => $count) {
                self::warnDroppedOnce($cause, $count);
            }
        });
    }

    /**
     * EXACTLY ONE line per process per cause, on the runtime's standard error
     * stream (error_log), ungated — never behind BOOSTHIS_DEBUG, for the same
     * reason warnStayedInactiveOnce is not: this exists for the developer who
     * does not yet suspect the kit. Names the cause and the fix in kit-owned
     * words; carries no server prose, no key, no endpoint.
     */
    private static function warnDroppedOnce(string $cause, int $count): void
    {
        if (isset(self::$droppedWarned[$cause])) {
            return;
        }
        self::$droppedWarned[$cause] = true;

        $text = self::DROP_CAUSE_TEXT[$cause] ?? null;
        $fix = self::DROP_CAUSE_FIX[$cause] ?? null;
        if ($text === null || $fix === null) {
            // An unknown cause is counted in the total but named nowhere.
            return;
        }
        error_log(
            "[boosthis] Boosthis dropped {$count} of the measurements this app sent: "
            . $text . '. ' . $fix
        );
    }

    /** Cumulative rows the server has refused since this install started, or 0
     *  when nothing has been dropped (or the readout fails). Read by the status
     *  page and the panel. */
    public static function droppedRowCount(): int
    {
        return (int) Safe::run(static function (): int {
            $rec = Store::get(self::KEY_DROPPED, null);

            return is_array($rec) ? self::finiteCount($rec['total'] ?? null) : 0;
        }, 0);
    }

    /**
     * The known causes seen so far, in the fixed cause order. Empty when
     * nothing has been dropped.
     *
     * @return list<string>
     */
    public static function droppedRowCauses(): array
    {
        $causes = Safe::run(static function (): array {
            $rec = Store::get(self::KEY_DROPPED, null);
            $stored = (is_array($rec) && isset($rec['causes']) && is_array($rec['causes']))
                ? $rec['causes']
                : [];
            $out = [];
            foreach (self::DROP_CAUSE_KEYS as $cause) {
                if (in_array($cause, $stored, true)) {
                    $out[] = $cause;
                }
            }

            return $out;
        }, []);

        return is_array($causes) ? $causes : [];
    }

    /**
     * The status-page / panel figure: `"6 \u2014 route names the privacy guard
     * refused; spans past the 20-span limit"` (em dash U+2014, causes joined
     * with "; "). Empty string when nothing has been dropped, so a healthy app
     * shows nothing at all. Never a ratio: `dropped` counts ROWS and has no
     * relationship to how many events were accepted.
     */
    public static function dropSummaryText(): string
    {
        return (string) Safe::run(static function (): string {
            $total = self::droppedRowCount();
            if ($total <= 0) {
                return '';
            }
            $words = [];
            foreach (self::droppedRowCauses() as $cause) {
                if (isset(self::DROP_CAUSE_TEXT[$cause])) {
                    $words[] = self::DROP_CAUSE_TEXT[$cause];
                }
            }
            if ($words === []) {
                return (string) $total;
            }

            return $total . " \u{2014} " . implode('; ', $words);
        }, '');
    }

    /** A non-negative integer read of a wire value, or 0 for anything that is
     *  not a positive finite number. */
    private static function finiteCount(mixed $v): int
    {
        if (!is_int($v) && !is_float($v)) {
            return 0;
        }
        if (!is_finite((float) $v) || $v <= 0) {
            return 0;
        }

        return (int) floor((float) $v);
    }

    private static function ensureRegistered(?string $projectKey, bool $explicit): void
    {
        if ($projectKey === null) {
            $chosen = (bool) (self::$projectKey['noKeyChosen'] ?? false);
            [$why, $fix] = $chosen
                ? ['this app is set to run with no project key', 'That is a deliberate setting: this app measures itself and never appears on your Boosthis dashboard. Nothing is being sent.']
                : ['no project key is wired into this app', 'Without one this app measures itself and never appears on your Boosthis dashboard. Copy a project key from your Boosthis Setup page into this app, then restart.'];
            self::warnInactivePairOnce($why, $fix);
            Registration::markRegistrationRefused();
            return;
        }
        if (self::isRegistered()) {
            Registration::markRegistrationConfirmed();
            self::refreshDirective($projectKey);
            return;
        }
        $last = Store::get(self::KEY_CONSENT_TRY, 0);
        $last = is_int($last) ? $last : 0;
        // Lazy retry: a project without a key must not hammer the API on every
        // request. An explicit enable() call bypasses the cooldown so a
        // developer who just pasted their key sees it work immediately.
        if (!$explicit && $last > 0 && (self::nowMs() - $last) < self::CONSENT_RETRY_MS) {
            return;
        }
        Store::set(self::KEY_CONSENT_TRY, self::nowMs());
        self::postConsent($projectKey, false);
    }

    /**
     * Re-ask the server what the dashboard "Full telemetry" switch currently
     * says, for an install that is ALREADY registered.
     *
     * Without this an install is stuck forever on whatever directive it captured
     * at first registration, so a change the owner makes in the dashboard never
     * reaches this copy — the defect the go/java/flutter/swift kits fixed by
     * consenting on launch. PHP has no launch to hang that on (every request is
     * a fresh interpreter), so the cap is a DURABLE timestamp: at most one extra
     * consent round-trip per hour across ALL requests, never one per request.
     *
     * postConsent preserves credentials on a tokenless idempotent 200 (it only
     * overwrites a token on a NON-EMPTY value), so this refresh can never wipe
     * the delete token and strand the install in a permanent 401.
     */
    private static function refreshDirective(?string $projectKey): void
    {
        // Under the kill switch there is no network at all — leave the stored
        // directive exactly as it is, like every other consent path.
        if (RuntimeFlags::disabled()) {
            return;
        }
        $last = Store::get(self::KEY_REFRESH_AT, 0);
        $last = is_int($last) ? $last : 0;
        $now = self::nowMs();
        if ($last > 0 && ($now - $last) < self::REFRESH_INTERVAL_MS) {
            return;
        }
        // Stamped BEFORE the call so a failed round-trip still spends the
        // interval — a server that is down must not be retried every request.
        Store::set(self::KEY_REFRESH_AT, $now);
        self::postConsent($projectKey, false);
    }

    /**
     * One ungated line when the PHP-specific environment value displaced a
     * different configured key. The full value is never written.
     *
     * @param array{key:?string,source:string,overrodeShared:bool,display:?string} $resolved
     */
    private static function warnProjectKeyOverrideOnce(array $resolved): void
    {
        if (!$resolved['overrodeShared'] || self::$projectKeyOverrideWarned) {
            return;
        }
        self::$projectKeyOverrideWarned = true;
        $display = $resolved['display'] ?? 'set';
        error_log(
            '[boosthis] This app is registering under its PHP project key from the environment '
            . "(ending {$display}), replacing a different configured key."
        );
    }

    private static function refuseInstallId(string $source): void
    {
        StartAnnounce::sayAfterStartupLine(
            "[boosthis] Boosthis refused a non-UUID install id from {$source}."
        );
    }

    private static function warnIdentityPersistenceOnce(string $cause): void
    {
        if (self::$identityPersistenceWarned) {
            return;
        }
        self::$identityPersistenceWarned = true;
        try {
            StartAnnounce::sayAfterStartupLine(
                "[boosthis] Boosthis cannot keep this install's identity between restarts (" . $cause
                . "). Every restart registers as a new install. Set BOOSTHIS_INSTALL_ID to a fixed UUID to pin it."
            );
        } catch (\Throwable) {
        }
    }

    /**
     * POST /installs/consent. Returns true when credentials were minted.
     *
     * $rotated guards the reinstall self-heal: if the server does not know this
     * install and we hold NO delete token, the id we are carrying is an orphan
     * (a copied .env, a restored backup). We rotate to a fresh id exactly once
     * and re-register, rather than retrying forever against an id the server
     * will never accept.
     */
    private static function postConsent(?string $projectKey, bool $rotated): bool
    {
        $installId = self::$installId;
        if ($installId === null) {
            return false;
        }
        $payload = [
            'installId' => $installId,
            'runtime' => self::RUNTIME,
            'packageVersion' => Thresholds::RUNTIME_VERSION,
            // WHERE THIS PROJECT RUNS. Sent unconditionally because all three
            // fields have a real answer even when nothing declares itself;
            // only a kit built before this existed should leave them blank.
            'hosting' => Hosting::facts(),
            'identity' => self::identityFacts(),
        ];
        if (self::$appName !== null) {
            $payload['appName'] = self::$appName;
        }

        $res = Safe::run(
            static fn (): array => Transmit::safeTransmit(
                RuntimeFlags::apiBase() . '/installs/consent',
                $payload,
                $projectKey,
                5000
            ),
            ['status' => 0, 'body' => null]
        );

        $status = (int) ($res['status'] ?? 0);
        $body = is_array($res['body'] ?? null) ? $res['body'] : null;

        // The orphan dead end — and the reason it is checked BEFORE the success
        // branch. When this install id is already registered but this copy holds
        // no credentials for it (a project key and id copied from a sibling app,
        // a restored backup, a rebuilt container), the server answers 200 with
        // {"status":"already_registered_no_token"} and guidance to mint a fresh
        // id. It is NOT a 404. Falling into the success branch below would
        // persist a credential-less registration that can never upload: the
        // install then looks connected, reports nothing, and says nothing — the
        // exact dead end the node, web, RN, python, go, java and ruby kits
        // already self-heal out of.
        if (self::isOrphanConsentResponse($status, $body)) {
            if (!$rotated && self::deleteToken() === null && trim((string) $projectKey) !== '') {
                return self::rotateInstallIdAndRetry($projectKey, $installId);
            }

            // Rotation has already happened (or cannot), and the answer is still
            // the orphan marker. This is NOT a registration: filing it as one
            // leaves an install that looks connected, uploads nothing, and never
            // explains itself. Say it once and stop.
            self::warnStayedInactiveOnce($status, $body);
            Registration::markRegistrationRefused();

            return false;
        }

        if (($status === 201 || $status === 200) && $body !== null) {
            self::persistConsent($installId, $body);
            Registration::markRegistrationConfirmed();

            return true;
        }

        // A malformed install id is a fixed, machine-readable rejection whose
        // body is frozen across kits: {"error":"invalid_install_id"}. Detect the
        // marker, warn ONCE, surface it, and do NOT arm the hourly retry — a bad
        // id will never fix itself, so retrying it is pure noise.
        if (self::isInvalidInstallIdResponse($body)) {
            self::$invalidInstallId = true;
            if (!self::$invalidInstallIdWarned) {
                self::$invalidInstallIdWarned = true;
                if (RuntimeFlags::debug()) {
                    error_log('[boosthis] server rejected install id as invalid_install_id');
                }
            }
            Registration::markRegistrationRefused();

            return false;
        }

        // The server has never heard of this id and we hold no delete token, so
        // the id is an orphan by the other route: rotate and re-register once.
        // The SAME non-blank-project-key guard as the 200 orphan branch above:
        // without a key the re-registration cannot succeed, so a keyless install
        // must NOT burn a fresh identity firing a futile unauthenticated retry.
        if ($status === 404 && !$rotated && self::deleteToken() === null && trim((string) $projectKey) !== '') {
            return self::rotateInstallIdAndRetry($projectKey, $installId);
        }

        if ($status === 401 || $status === 403) {
            Registration::markRegistrationRefused();
        } else {
            Registration::markRegistrationUnreachable();
        }
        self::warnStayedInactiveOnce($status, $body);

        return false;
    }

    /**
     * The rejection body carrying the fixed {error:"invalid_install_id"}
     * marker — matched on the body, which is the frozen wire, not on a status
     * code that could differ between deployments.
     *
     * @param array<mixed>|null $body
     */
    private static function isInvalidInstallIdResponse(?array $body): bool
    {
        return is_array($body) && ($body['error'] ?? null) === self::INVALID_INSTALL_ID_MARKER;
    }

    /**
     * The server's orphan answer: HTTP 200 carrying the frozen marker
     * {"status":"already_registered_no_token"}. Matched on the BODY, because
     * the status here is an ordinary success code — which is exactly why a kit
     * that only looks at the status walks straight past it.
     *
     * @param array<mixed>|null $body
     */
    private static function isOrphanConsentResponse(int $status, ?array $body): bool
    {
        if ($status !== 200 || !is_array($body)) {
            return false;
        }

        return (string) ($body['status'] ?? '') === self::ORPHAN_INSTALL_MARKER;
    }

    /**
     * Mint a fresh install id, remember the one it replaced, and re-register
     * exactly once — the $rotated flag passed back in closes the loop, so a
     * server that keeps answering the same way can never start a cycle.
     */
    private static function rotateInstallIdAndRetry(?string $projectKey, string $installId): bool
    {
        $fresh = self::newInstallId();
        Store::update(self::KEY_INSTALL, static function ($cur) use ($installId, $fresh) {
            $rec = is_array($cur) ? $cur : [];
            // Keep the mapping so an operator can see what the id used to be.
            $rec['previousInstallId'] = $installId;
            $rec['installId'] = $fresh;

            return $rec;
        }, []);
        self::$installId = $fresh;

        return self::postConsent($projectKey, true);
    }

    /**
     * EXACTLY ONE line per process when registration did not go through,
     * mirroring the java kit's warnRegistrationRejectedOnce and the ruby kit's
     * warnStayedInactiveOnce.
     *
     * Silence is the wrong answer here and this is the one place the kit says
     * so out loud. A kit that cannot register looks identical to a kit that is
     * working — same silent process, same absent numbers — so the developer
     * goes looking in the wrong place, usually for hours. The line names the
     * coarse reason and the next move; it carries no project key, no endpoint
     * and no server prose, only kit-generated text.
     *
     * It is NOT gated on BOOSTHIS_DEBUG: a debug flag only helps the developer
     * who already suspects the kit, and this exists for the one who does not.
     * One line, once, is the whole budget. It goes to the runtime's standard
     * error stream (error_log), never to the host's own output.
     *
     * @param array<mixed>|null $body
     */
    private static function warnStayedInactiveOnce(int $status, ?array $body): void
    {
        [$why, $fix] = self::registrationFailureHint($status, $body);
        self::warnInactivePairOnce($why, $fix);
    }

    private static function warnInactivePairOnce(string $why, string $fix): void
    {
        self::$inactiveNotice = "Boosthis stayed inactive because {$why}. {$fix}";
        if (self::$inactiveWarned) {
            return;
        }
        self::$inactiveWarned = true;
        error_log("[boosthis] " . self::$inactiveNotice);
    }

    /**
     * [why, what to do] for the one-shot line above. Derived from the status
     * and the frozen error marker only — never from server text.
     *
     * @param array<mixed>|null $body
     * @return array{0:string,1:string}
     */
    private static function registrationFailureHint(int $status, ?array $body): array
    {
        $marker = is_array($body) ? (string) ($body['error'] ?? '') : '';

        if (self::isOrphanConsentResponse($status, $body)) {
            // The one case that reads like success on the wire: HTTP 200, and
            // the install still cannot upload. Name it plainly.
            return [
                'this install id already belongs to another copy of the app, and this copy holds none of its credentials',
                'Unset BOOSTHIS_INSTALL_ID (or give this copy its own fresh UUID) and restart.',
            ];
        }
        if ($status === 0) {
            // No HTTP answer at all: DNS, TLS, egress or a proxy. We still name
            // a coarse cause so a broken certificate store or a blocked egress
            // is not left as an unactionable "could not reach the API" — but the
            // parenthetical is a KIT-OWNED classification, never the caught
            // transport error's raw text (which can embed a request URL, a
            // credential-bearing URL, or remote-supplied prose).
            $detail = self::transportFailureParenthetical(self::lastRecordedError());

            return [
                "it could not reach the Boosthis API from this process{$detail}",
                'Check outbound HTTPS and certificate trust from this app, then restart.',
            ];
        }
        if ($status === 401 || $status === 403) {
            return match ($marker) {
                'invite_key_revoked' => ['its project key has been revoked', 'A revoked key never works again. Mint a new project key in your Boosthis dashboard, put it in this app, then restart.'],
                'plan_required', 'account_closure_pending' => ['its project key is paused by a billing problem on the account', 'Settle the account in your Boosthis dashboard and the same key starts working again. Nothing has been revoked.'],
                'invite_key_unknown' => ['its project key was not recognised', 'Check it is the current key, pasted whole, with no stray spaces, and that it belongs to this project.'],
                default => ["its project key wasn't accepted", 'Check it is the current key, pasted whole, with no stray spaces, and that it belongs to this project.'],
            };
        }

        return [
            "the server refused the registration (HTTP {$status})",
            'Retry later; if it keeps happening, check the project key in your Boosthis dashboard.',
        ];
    }

    /** The kit's own last caught error, or null. Never throws. */
    private static function lastRecordedError(): ?\Throwable
    {
        return Safe::run(static fn (): ?\Throwable => Safe::lastError(), null);
    }

    /**
     * A KIT-OWNED parenthetical classifying a caught transport error, for the
     * "could not reach the API" line only.
     *
     * The caught transport error's raw text is NOT kit-generated: a TLS/proxy/
     * HTTP client message can embed the request URL, a credential-bearing URL,
     * or remote-supplied prose. So this reads the error's message and its class
     * name FOR MATCHING ONLY and emits a fixed, kit-owned phrase — the message
     * text never appears in the output, in whole or in part. A no-match falls
     * back to the sanitised class name alone (never the message).
     *
     * Guest-safe: a null or odd error yields no parenthetical, it never throws,
     * never allocates unboundedly, and never touches the network.
     */
    private static function transportFailureParenthetical(?\Throwable $e): string
    {
        if ($e === null) {
            return '';
        }

        // Cap EACH input before it is copied or case-converted, so a
        // pathological message can never drive unbounded work in a host process.
        $message = Safe::run(static fn (): string => substr((string) $e->getMessage(), 0, 500), '');
        $type = Safe::run(static fn (): string => substr(get_class($e), 0, 128), '');

        $hay = strtolower($message . ' ' . $type);

        // First match wins. Each phrase is fixed, kit-generated text.
        $rules = [
            [['certificate', 'tls', 'ssl', 'x509', 'self signed', 'self-signed',
              'unable to verify', 'certificate_verify_failed', 'sslhandshake',
              'trust anchor', 'unable to get local issuer'],
             ' (the certificate store rejected the connection)'],
            [['getaddrinfo', 'name or service not known', 'enotfound', 'unknownhost',
              'nodename nor servname', 'no such host', 'failed to resolve', 'dns'],
             ' (the API host name did not resolve)'],
            [['econnrefused', 'connection refused'],
             ' (the connection was refused)'],
            [['etimedout', 'timeout', 'timed out'],
             ' (the connection timed out)'],
            [['proxy'],
             ' (a proxy rejected the connection)'],
            [['enetunreach', 'network is unreachable', 'no route to host'],
             ' (the network was unreachable)'],
        ];
        foreach ($rules as [$needles, $phrase]) {
            foreach ($needles as $needle) {
                if (str_contains($hay, $needle)) {
                    return $phrase;
                }
            }
        }

        // No match: emit the TYPE/CLASS NAME ONLY (never the message), sanitised
        // to a safe charset and truncated. If nothing survives, emit nothing.
        $safe = substr((string) preg_replace('/[^A-Za-z0-9_.]/', '', $type), 0, 60);

        return $safe === '' ? '' : " ({$safe})";
    }

    /** @param array<mixed> $body */
    private static function persistConsent(string $installId, array $body): void
    {
        Store::update(self::KEY_INSTALL, static function ($cur) use ($installId, $body) {
            $rec = is_array($cur) ? $cur : [];
            if (($rec['installId'] ?? null) !== $installId) {
                unset(
                    $rec['deleteToken'],
                    $rec['readToken'],
                    $rec['registeredAt'],
                    $rec['fullTelemetry'],
                    $rec['disconnected']
                );
            }
            $rec['installId'] = is_string($body['installId'] ?? null)
                ? $body['installId'] : $installId;
            // The delete token is the one credential we cannot lose.
            if (is_string($body['deleteToken'] ?? null) && $body['deleteToken'] !== '') {
                $rec['deleteToken'] = $body['deleteToken'];
            }
            if (is_string($body['readToken'] ?? null) && $body['readToken'] !== '') {
                $rec['readToken'] = $body['readToken'];
            }
            $rec['fullTelemetry'] = (bool) ($body['fullTelemetry'] ?? false);
            $rec['disconnected'] = (bool) ($body['disconnected'] ?? false);
            if (!isset($rec['registeredAt'])) {
                $rec['registeredAt'] = (int) (microtime(true) * 1000);
            }
            $rec['appName'] = self::$appName;

            return $rec;
        }, []);
        // Kept in its own install-scoped store entry, beside (not inside) the
        // credential record. An older reply omitting both fields changes nothing.
        ProjectIdentity::recordConsent($installId, $body);
    }

    /**
     * POST /installs/forget — hand the delete token back and erase local state.
     * This is the "forget me" lever; it must sweep every scope the kit wrote.
     */
    public static function forget(): bool
    {
        return (bool) Safe::run(static function (): bool {
            Candidates::clear();
            $installId = self::installId();
            $token = self::deleteToken();
            if ($installId === null || $token === null) {
                Store::forgetAll();

                return true;
            }
            $res = Transmit::safeTransmitTrustedPayload(
                RuntimeFlags::apiBase() . '/installs/forget',
                ['installId' => $installId, 'deleteToken' => $token],
                $token,
                5000
            );
            Store::forgetAll();
            self::$enabled = false;
            self::$installId = null;

            return in_array((int) $res['status'], [200, 204], true);
        }, false);
    }

    public static function newInstallId(): string
    {
        $data = Safe::run(static fn (): string => random_bytes(16), '');
        if ($data === '' || strlen($data) !== 16) {
            $data = pack('NNNN', mt_rand(), mt_rand(), mt_rand(), mt_rand());
        }
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function isUuid(?string $v): bool
    {
        return $v !== null && preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $v
        ) === 1;
    }

    /** Closed-vocabulary persistence facts sent with every consent. */
    private static function identityFacts(): array
    {
        if (self::$identityPinned) {
            return ['source' => 'host', 'store' => 'none', 'restored' => false];
        }
        $disk = Store::identityDiskInfo(self::KEY_INSTALL, self::$installId);
        $store = $disk['disk']
            ? (RuntimeFlags::stateDir() !== null ? 'configured' : 'scratch')
            : 'memory';

        return [
            'source' => 'store',
            'store' => $store,
            'restored' => self::$identityRestored,
        ];
    }

    public static function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }

    /** Test seam. */
    public static function resetForTests(): void
    {
        self::$enabled = false;
        self::$installId = null;
        self::$appName = null;
        self::$explicitFullTelemetry = false;
        self::$invalidInstallId = false;
        self::$invalidInstallIdWarned = false;
        self::$inactiveWarned = false;
        self::$inactiveNotice = null;
        self::$projectKeyOverrideWarned = false;
        self::$identityPersistenceWarned = false;
        self::$identityPinned = false;
        self::$identityRestored = false;
        self::$projectKey = null;
        ProjectIdentity::resetForTests();
        self::resetDropReportForTests();
    }

    /** Test seam — forget the per-process drop warn-once latch, alongside the
     *  reset hook above. The cumulative count lives in the Store and is cleared
     *  by Store::forgetAll(); only the in-process latch lives here. */
    public static function resetDropReportForTests(): void
    {
        self::$droppedWarned = [];
    }
}
