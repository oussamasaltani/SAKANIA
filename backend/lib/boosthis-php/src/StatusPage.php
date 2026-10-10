<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * The standalone "is Boosthis working?" page — the one surface a back-end
 * developer can open when their PHP app has no UI at all.
 *
 * WHY THIS EXISTS. The floating bubble proves the kit is alive by riding along
 * on a page the app already serves. An API with no HTML has nowhere to put it,
 * so the developer installs the kit, sees nothing, and reasonably concludes the
 * product is broken. This page is the answer: one self-contained HTML document,
 * served by the kit on the HOST APP'S OWN PORT, that states plainly whether the
 * kit registered, whether anything is being uploaded, and — when the answer is
 * no — why not.
 *
 * CONTRACT (identical in the Node, Python, Go, Java, PHP, .NET and Ruby kits —
 * change it in one, change it in all):
 *   - Path:    `<prefix>/status`, canonically `/_boosthis/status`. GET only.
 *   - Guard:   the kit's STRICT loopback rule — direct loopback TCP peer AND no
 *              proxy/forwarding headers. It is NOT a new auth surface: a remote
 *              request gets 403 and learns nothing about the install.
 *   - Body:    server-rendered HTML with inline CSS, NO JavaScript, NO network
 *              calls, NO external assets. The page has to work in exactly the
 *              situation where everything else is broken.
 *   - Secrets: the install id is shown (it is an opaque identifier the
 *              dashboard also shows); the delete token and read token are
 *              NEVER rendered here.
 *   - Honesty: the headline and explanation come from the SAME ordered state
 *              machine as the bubble's Bubble::computePanelNotice (rejected ->
 *              not registered -> sharing off -> measuring), with the same
 *              wording, so a developer never gets two different stories.
 *
 * PHP has no long-lived process to register routes on and this kit registers no
 * HTTP routes today, so the page is served two ways, both wired to the same
 * strict guard: StatusPage::serve() is a zero-dependency front-controller hook
 * for any plain PHP app, and Middleware / LaravelMiddleware short-circuit the
 * path so a framework app needs no manual step.
 */
final class StatusPage
{
    /** The status-page path suffix, appended to the mount prefix. */
    public const STATUS_SUFFIX = '/status';

    /** The standalone status page path. Canonical, matched exactly. */
    public const STATUS_PATH = '/_boosthis/status';

    /** Human label for this runtime, shown in the page's "Runtime" row. */
    private const RUNTIME_LABEL = 'PHP';

    /** The kit option a developer flips to turn sharing on from code. Spelled
     *  the way THIS language spells it — the only value in the honesty wording
     *  that legitimately differs between kits. */
    private const SHARE_OPTION = 'shareMeterWithAI: true';

    /** The dashboard link used when there is no endpoint to derive an origin
     *  from (or it will not parse). */
    private const DEFAULT_DASHBOARD_URL = 'https://www.boosthis.com/dashboard';

    /**
     * Headers that a same-host reverse proxy adds to forwarded requests. Their
     * presence means a loopback peer is due to the proxy hop, not a direct
     * local connection — so the loopback guard must not grant access. This is
     * the same set the Node kit's loopback guard rejects.
     */
    private const PROXY_HEADERS = [
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_FORWARDED_HOST',
        'HTTP_X_FORWARDED_PROTO',
        'HTTP_X_REAL_IP',
        'HTTP_FORWARDED',
    ];

    /** Loopback peers that satisfy the direct-local rule. */
    private const LOOPBACK = ['127.0.0.1', '::1', '::ffff:127.0.0.1'];

    /** Shared palette — the kit's dark theme, byte-equal to the bubble's. */
    private const BG = '#0b0c10';
    private const CARD = '#15171c';
    private const BR = '#262932';
    private const FG = '#e6e7eb';
    private const MUT = '#8b8f99';
    private const PRI = '#f97316';
    private const GOOD = '#4ade80';
    private const WARN = '#fbbf24';
    private const BAD = '#f87171';

    private function __construct()
    {
    }

    /**
     * Zero-dependency front-controller hook. Call it as early in the request as
     * possible, before the framework boots:
     *
     *     if (\Boosthis\StatusPage::serve()) { return; }
     *
     * On a GET to the status path it enforces the strict loopback rule against
     * the CGI request superglobals and either emits the page (loopback) or the
     * 403 body (anything else), returning TRUE so the host app stops. Any other
     * request returns FALSE and the host app continues normally.
     *
     * A non-loopback request to the status path is HANDLED here (403), never
     * allowed to fall through — the page's existence must not leak from a probe
     * that then reaches the host's own routing.
     */
    public static function serve(): bool
    {
        return (bool) Safe::run(static function (): bool {
            $server = $_SERVER ?? [];
            if (!self::isStatusRequest($server)) {
                return false;
            }

            if (self::isDirectLoopback($server)) {
                self::emit(200, self::html());
            } else {
                self::emit(403, self::forbiddenHtml());
            }

            return true;
        }, false);
    }

    /**
     * True when this request is a GET to the canonical status path. The path is
     * read from REQUEST_URI (query string stripped) so it works under any SAPI.
     *
     * @param array<string,mixed> $server
     */
    public static function isStatusRequest(array $server): bool
    {
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET') {
            return false;
        }
        $uri = (string) ($server['REQUEST_URI'] ?? ($server['PATH_INFO'] ?? ''));
        $q = strpos($uri, '?');
        if ($q !== false) {
            $uri = substr($uri, 0, $q);
        }
        if ($uri === '') {
            return false;
        }
        if ($uri !== self::STATUS_PATH) {
            // Tolerate a trailing slash but nothing else.
            return rtrim($uri, '/') === self::STATUS_PATH;
        }

        return true;
    }

    /**
     * The kit's STRICT loopback rule, applied to CGI request superglobals: the
     * real TCP peer (REMOTE_ADDR) is loopback AND no proxy/forwarding header is
     * present. Deliberately does NOT honour X-Forwarded-For — behind a same-host
     * reverse proxy every external request arrives with a loopback REMOTE_ADDR
     * plus a forwarding header, and trusting the header would make this page
     * reachable from the public internet. No option opens it.
     *
     * Fail-safe: any error answers false — a guard that throws must never grant
     * access.
     *
     * @param array<string,mixed> $server
     */
    /**
     * Fold header NAMES from a framework's own header bag into the CGI-style
     * keys the guard reads, so a forwarding header the framework knows about
     * can never be invisible to it.
     *
     * PSR-7 and Symfony both keep headers in a bag that is SEPARATE from the
     * CGI server params, and a server is free to populate one without the
     * other: a request can carry X-Forwarded-For in its header bag while its
     * server params say only REMOTE_ADDR=127.0.0.1. Reading the server params
     * alone would then serve the page to a proxied caller from the internet.
     *
     * Only presence matters to the guard, so the value is never copied — an
     * empty placeholder is enough, and it cannot overwrite a real one.
     *
     * @param array<string,mixed> $server
     * @param array<int,mixed>    $names header names as the framework spells them
     * @return array<string,mixed>
     */
    public static function withHeaderNames(array $server, array $names): array
    {
        foreach ($names as $name) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
            if (!array_key_exists($key, $server)) {
                $server[$key] = '';
            }
        }

        return $server;
    }

    public static function isDirectLoopback(array $server): bool
    {
        return (bool) Safe::run(static function () use ($server): bool {
            $peer = (string) ($server['REMOTE_ADDR'] ?? '');
            if (!in_array($peer, self::LOOPBACK, true)) {
                return false;
            }
            foreach (self::PROXY_HEADERS as $h) {
                if (array_key_exists($h, $server)) {
                    return false;
                }
            }

            return true;
        }, false);
    }

    /**
     * Emit the response: status line, the two fixed headers, then the body.
     * Never writes headers twice — if the host already sent some (it should not
     * have this early), it degrades to echoing the body.
     */
    private static function emit(int $status, string $body): void
    {
        Safe::fire(static function () use ($status, $body): void {
            if (!headers_sent()) {
                http_response_code($status);
                header('Content-Type: text/html; charset=utf-8');
                header('Cache-Control: no-store');
            }
            echo $body;
        });
    }

    /**
     * Render the status page. Collect the kit's live facts, then format them.
     * Never throws: any readout failure degrades to the most conservative
     * honest answer (registration unknown, nothing uploaded) rather than throwing
     * into the host's request handling.
     */
    public static function html(?int $nowMs = null): string
    {
        $now = $nowMs ?? Telemetry::nowMs();

        return (string) Safe::run(static function () use ($now): string {
            return self::render(self::collect(), $now);
        }, self::render(self::conservativeFacts(), $now));
    }

    /**
     * The body served to a request that did not come from this machine. Same
     * local-only posture as the bubble's loopback-only surfaces, and it
     * discloses nothing about the install.
     */
    public static function forbiddenHtml(): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="robots" content="noindex,nofollow">'
            . '<title>Boosthis status</title><style>'
            . 'body{margin:0;background:' . self::BG . ';color:' . self::FG . ';'
            . 'font:14px/1.6 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;padding:32px 20px}'
            . 'main{max-width:620px;margin:0 auto}h1{font-size:20px;margin:0 0 8px}'
            . 'p{color:' . self::MUT . ';margin:0}'
            . '</style></head><body><main>'
            . '<h1>Boosthis status is local-only</h1>'
            . '<p>This page is served only to requests from the machine running this app. '
            . 'Open it from that machine.</p>'
            . '</main></body></html>';
    }

    /**
     * Read the kit's live state, each readout guarded so a failure degrades to
     * the conservative default instead of throwing.
     *
     * @return array<string,mixed>
     */
    public static function collect(): array
    {
        $installId = Safe::run(static fn (): ?string => Telemetry::installId(), null);
        $registered = (bool) Safe::run(static fn (): bool => Telemetry::isRegistered(), false);
        Safe::fire(static function (): void {
            if (Telemetry::isRegistered()) {
                Registration::markRegistrationConfirmed();
            }
            Registration::checkOnce(
                RuntimeFlags::apiBase(),
                Telemetry::installId(),
                Telemetry::projectKey()
            );
        });
        $registrationVerdict = (string) Safe::run(
            static fn (): string => Registration::verdict(),
            'unknown'
        );
        if ($registrationVerdict === 'unknown' && $registered) {
            $registrationVerdict = 'registered';
        }
        $sharing = (bool) Safe::run(static fn (): bool => Telemetry::fullTelemetry(), false);
        $rejected = (bool) Safe::run(static fn (): bool => Telemetry::invalidInstallId(), false);
        $lastUpload = Safe::run(static fn (): ?int => Telemetry::lastUploadAt(), null);
        $lastFailure = Safe::run(static fn (): ?array => Telemetry::lastUploadFailure(), null);
        $uploadsDropped = (int) Safe::run(static fn (): int => Telemetry::droppedUploadCount(), 0);
        $lastAttemptFailed = (bool) Safe::run(
            static fn (): bool => Telemetry::isLastUploadAttemptFailed(),
            false
        );
        $rowsDropped = (int) Safe::run(static fn (): int => Telemetry::droppedRowCount(), 0);
        $rowsDroppedText = (string) Safe::run(static fn (): string => Telemetry::dropSummaryText(), '');
        $measured = (int) Safe::run(static fn (): int => Samples::count(), 0);
        $endpoint = (string) Safe::run(static fn (): string => RuntimeFlags::apiBase(), '');
        $projectDisplay = (string) Safe::run(
            static fn (): string => ProjectIdentity::displayCurrent(),
            ProjectIdentity::PROJECT_UNKNOWN_TEXT
        );
        $projectKey = Safe::run(
            static fn (): array => Telemetry::projectKeyDetails(),
            ['key' => null, 'source' => ProjectKey::SOURCE_NONE, 'overrodeShared' => false, 'display' => null]
        );

        // Where this project runs must remain readable when every remote
        // service is unavailable. An unreadable local declaration is not
        // evidence that no platform was declared, so it gets its own answer.
        $whereItRuns = 'Could not be read';
        $hostingIsPreview = false;
        try {
            $hosting = Hosting::facts();
            $whereItRuns = Hosting::line($hosting);
            $hostingIsPreview = $hosting['environment'] === 'preview'
                || $hosting['environment'] === 'development';
        } catch (\Throwable) {
            $whereItRuns = 'Could not be read';
            $hostingIsPreview = false;
        }

        // Same order as Bubble::computePanelNotice: a server-refused id first,
        // then a kit that never registered, then a registered-but-silent
        // install, else measuring.
        $state = $rejected
            ? 'install-id-not-uuid'
            : ($registrationVerdict === 'unregistered'
                ? 'not-registered'
                : ($registrationVerdict === 'unknown'
                    ? 'registration-unknown'
                    : (!$sharing
                        ? 'sharing-off'
                        : ($lastAttemptFailed && is_array($lastFailure)
                            ? 'uploads-failing' : 'measuring'))));

        return [
            'state' => $state,
            'inactiveNotice' => Telemetry::inactiveNotice(),
            'installId' => is_string($installId) && $installId !== '' ? $installId : null,
            'registered' => $registered,
            'registrationVerdict' => $registrationVerdict,
            'sharing' => $sharing,
            'fullTelemetry' => $sharing,
            'lastUploadAt' => is_int($lastUpload) ? $lastUpload : null,
            'lastUploadFailAt' => is_array($lastFailure) ? ($lastFailure['at'] ?? null) : null,
            'lastUploadFailReason' => is_array($lastFailure) ? ($lastFailure['reason'] ?? null) : null,
            'uploadsDropped' => $uploadsDropped,
            // Rows the server ACCEPTED the batch for and then refused. Zero
            // when the server never reported any (an older server, or a healthy
            // app) — the page then shows nothing at all.
            'rowsDropped' => $rowsDropped,
            'rowsDroppedText' => $rowsDroppedText,
            'requestsMeasured' => $measured,
            'kitVersion' => Thresholds::RUNTIME_VERSION,
            'runtime' => self::RUNTIME_LABEL,
            'projectDisplay' => $projectDisplay,
            'projectKeyDisplay' => $projectKey['display'],
            'projectKeySource' => ProjectKey::describe($projectKey),
            'hostingLine' => $whereItRuns,
            'hostingIsPreview' => $hostingIsPreview,
            'dashboardUrl' => self::dashboardUrlFor($endpoint !== '' ? $endpoint : null),
        ];
    }

    /** The most conservative honest facts, used when a full readout throws. */
    private static function conservativeFacts(): array
    {
        return [
            'state' => 'registration-unknown',
            'installId' => null,
            'registered' => false,
            'registrationVerdict' => 'unknown',
            'sharing' => false,
            'fullTelemetry' => false,
            'lastUploadAt' => null,
            'lastUploadFailAt' => null,
            'lastUploadFailReason' => null,
            'uploadsDropped' => 0,
            'rowsDropped' => 0,
            'rowsDroppedText' => '',
            'requestsMeasured' => 0,
            'kitVersion' => Thresholds::RUNTIME_VERSION,
            'runtime' => self::RUNTIME_LABEL,
            'projectDisplay' => ProjectIdentity::PROJECT_UNKNOWN_TEXT,
            'projectKeyDisplay' => null,
            'projectKeySource' => 'no project key configured',
            // A total readout failure cannot honestly be called an undeclared
            // platform or production, because neither fact was available.
            'hostingLine' => 'Could not be read',
            'hostingIsPreview' => false,
            'dashboardUrl' => self::DEFAULT_DASHBOARD_URL,
        ];
    }

    /**
     * Derive the project dashboard URL from the telemetry endpoint's origin, so
     * a self-hosted or staging endpoint links to ITS dashboard rather than
     * lying about where this install's data went. Falls back to the public
     * dashboard when there is no endpoint or it will not parse.
     */
    public static function dashboardUrlFor(?string $endpoint): string
    {
        if ($endpoint === null || $endpoint === '') {
            return self::DEFAULT_DASHBOARD_URL;
        }
        $parts = @parse_url($endpoint);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return self::DEFAULT_DASHBOARD_URL;
        }
        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin . '/dashboard';
    }

    /**
     * Headline + explanation for a state. The wording is the panel notice's,
     * word for word, except that the sharing-off line says "measurements stay
     * inside this process" instead of "these meters stay on this screen" — this
     * page shows no meters, and it must not describe something the reader cannot
     * see. Byte-identical across kits apart from <SHARE_OPTION>.
     *
     * @return array{title:string,body:string,tone:string}
     */
    public static function headline(string $state): array
    {
        if ($state === 'install-id-not-uuid') {
            return [
                'title' => 'Registration rejected',
                'body' => 'Install ID must be a UUID. Mint a real UUID and restart.',
                'tone' => 'bad',
            ];
        }
        if ($state === 'not-registered') {
            return [
                'title' => 'Not registered yet',
                'body' => 'This app has not registered with Boosthis, so nothing is being '
                    . "sent. Check the project key and this app's outbound network "
                    . 'access, then restart.',
                'tone' => 'bad',
            ];
        }
        if ($state === 'registration-unknown') {
            return [
                'title' => "Can't check right now",
                'body' => 'Boosthis could not be reached to confirm whether this app is '
                    . 'registered, so this page cannot say either way yet. This is not a '
                    . 'failed install and it does not mean anything stopped — it settles '
                    . 'by itself once the check goes through. Do not change the install '
                    . "line's id while this is showing.",
                'tone' => 'warn',
            ];
        }
        if ($state === 'sharing-off') {
            return [
                'title' => 'Nothing is being uploaded',
                'body' => 'This app is registered, but sharing is off: measurements stay '
                    . 'inside this process and your Boosthis dashboard stays empty. Turn '
                    . 'sharing on there, or start the kit with ' . self::SHARE_OPTION . '.',
                'tone' => 'warn',
            ];
        }
        if ($state === 'uploads-failing') {
            return [
                'title' => 'Uploads are not getting through',
                'body' => 'This app is registered and sharing is on, but the last batch of measurements did not reach Boosthis, so your dashboard is missing the most recent data. The rows below say what happened and how much has been lost.',
                'tone' => 'bad',
            ];
        }

        return [
            'title' => 'Registered and measuring',
            'body' => 'This app is registered with Boosthis and its measurements are being '
                . 'uploaded. Open the dashboard below to see them.',
            'tone' => 'good',
        ];
    }

    /**
     * `2026-08-20 09:12:44 UTC (34s ago)` — absolute so it can be compared with
     * a server-side log, relative so "is it alive right now?" needs no
     * arithmetic. Formatted identically in every kit.
     */
    public static function formatLastUpload(?int $atMs, int $nowMs): string
    {
        if ($atMs === null) {
            return 'Nothing uploaded yet';
        }
        $secsUtc = intdiv($atMs, 1000);
        $stamp = gmdate('Y-m-d H:i:s', $secsUtc) . ' UTC';
        $secs = (int) max(0, intdiv($nowMs - $atMs, 1000));
        if ($secs < 60) {
            $age = $secs . 's ago';
        } elseif ($secs < 3600) {
            $age = intdiv($secs, 60) . 'm ago';
        } else {
            $age = intdiv($secs, 3600) . 'h ago';
        }

        return $stamp . ' (' . $age . ')';
    }

    public static function uploadFailText(string $reason): string
    {
        if ($reason === 'unauthorized') {
            return 'Refused — credentials rejected';
        }
        if ($reason === 'rejected') {
            return 'Refused — batch rejected';
        }
        if ($reason === 'server-error') {
            return 'Boosthis failed to store it';
        }
        return 'No answer — timed out or unreachable';
    }

    /** Escape every dynamic value before it reaches the document. */
    private static function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function row(string $label, string $value, ?string $color = null): string
    {
        $style = $color !== null ? ' style="color:' . $color . '"' : '';

        return '<div class="r"><span class="k">' . self::esc($label) . '</span>'
            . '<span class="v"' . $style . '>' . self::esc($value) . '</span></div>';
    }

    /**
     * Render the page from collected facts. Pure: everything it shows comes from
     * $facts + $now.
     *
     * @param array<string,mixed> $facts
     */
    private static function render(array $facts, int $now): string
    {
        $state = (string) ($facts['state'] ?? 'not-registered');
        $h = self::headline($state);
        if ($state === 'not-registered' && is_string($facts['inactiveNotice'] ?? null)) {
            $h['body'] = $facts['inactiveNotice'];
        }
        $tone = $h['tone'];
        $toneColor = $tone === 'good' ? self::GOOD : ($tone === 'warn' ? self::WARN : self::BAD);

        $registered = (bool) ($facts['registered'] ?? false);
        $registrationVerdict = (string) (
            $facts['registrationVerdict'] ?? ($registered ? 'registered' : 'unregistered')
        );
        $sharing = (bool) ($facts['sharing'] ?? false);
        $fullTelemetry = (bool) ($facts['fullTelemetry'] ?? false);
        $installId = $facts['installId'] ?? null;
        $lastUpload = $facts['lastUploadAt'] ?? null;
        $lastUploadFailAt = $facts['lastUploadFailAt'] ?? null;
        $lastUploadFailReason = $facts['lastUploadFailReason'] ?? null;
        $uploadsDropped = (int) ($facts['uploadsDropped'] ?? 0);
        $rowsDropped = (int) ($facts['rowsDropped'] ?? 0);
        $rowsDroppedText = (string) ($facts['rowsDroppedText'] ?? '');
        $measured = (int) ($facts['requestsMeasured'] ?? 0);
        $kitVersion = (string) ($facts['kitVersion'] ?? Thresholds::RUNTIME_VERSION);
        $runtime = (string) ($facts['runtime'] ?? self::RUNTIME_LABEL);
        $projectDisplay = (string) (
            $facts['projectDisplay'] ?? ProjectIdentity::PROJECT_UNKNOWN_TEXT
        );
        $projectKeyDisplay = $facts['projectKeyDisplay'] ?? null;
        $projectKeySource = (string) ($facts['projectKeySource'] ?? 'no project key configured');
        $hostingLine = (string) ($facts['hostingLine'] ?? 'Could not be read');
        $hostingIsPreview = (bool) ($facts['hostingIsPreview'] ?? false);
        $dashboardUrl = (string) ($facts['dashboardUrl'] ?? self::DEFAULT_DASHBOARD_URL);

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow">'
            . '<title>Boosthis status</title><style>'
            . ':root{color-scheme:dark}body{margin:0;background:' . self::BG . ';color:' . self::FG . ';'
            . 'font:14px/1.6 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;'
            . 'padding:32px 20px}main{max-width:620px;margin:0 auto}'
            . '.kick{color:' . self::PRI . ';font-size:11px;letter-spacing:.16em;font-weight:700}'
            . 'h1{font-size:22px;margin:10px 0 8px;font-weight:650}'
            . 'p.lead{color:' . self::MUT . ';margin:0 0 22px}'
            . '.card{background:' . self::CARD . ';border:1px solid ' . self::BR . ';border-radius:16px;padding:6px 16px}'
            . '.r{display:flex;justify-content:space-between;gap:16px;padding:11px 0;'
            . 'border-bottom:1px solid ' . self::BR . ';margin-bottom:0}'
            . '.r:last-child{border-bottom:none}'
            . '.k{color:' . self::MUT . '}.v{font-variant-numeric:tabular-nums;text-align:right;word-break:break-all}'
            . 'a.dash{display:inline-block;margin-top:20px;background:' . self::PRI . ';color:#0b0c10;'
            . 'font-weight:650;text-decoration:none;padding:11px 18px;border-radius:12px}'
            . 'footer{color:' . self::MUT . ';font-size:12px;margin-top:22px}'
            . '</style></head><body><main>'
            . '<div class="kick">BOOSTHIS</div>'
            . '<h1 style="color:' . $toneColor . '">' . self::esc($h['title']) . '</h1>'
            . '<p class="lead">' . self::esc($h['body']) . '</p>'
            . '<div class="card">'
            . self::row(ProjectIdentity::PROJECT_LABEL, $projectDisplay)
            . self::row(
                ProjectIdentity::INSTALL_ID_LABEL,
                is_string($installId) && $installId !== ''
                    ? $installId : ProjectIdentity::INSTALL_ID_UNKNOWN_TEXT
            )
            . self::row(
                'Registered',
                $registrationVerdict === 'registered'
                    ? 'Yes'
                    : ($registrationVerdict === 'unregistered' ? 'No' : "Can't tell right now"),
                $registrationVerdict === 'registered'
                    ? self::GOOD
                    : ($registrationVerdict === 'unregistered' ? self::BAD : self::WARN)
            )
            . self::row('Sharing', $sharing ? 'On' : 'Off', $sharing ? self::GOOD : self::WARN)
            . self::row('Telemetry mode', $fullTelemetry ? 'Full telemetry' : 'Private (issues only)')
            . self::row('Last upload', self::formatLastUpload(is_int($lastUpload) ? $lastUpload : null, $now))
            . (is_int($lastUploadFailAt) && is_string($lastUploadFailReason)
                ? self::row('Last upload failed', self::formatLastUpload($lastUploadFailAt, $now) . ' — ' . self::uploadFailText($lastUploadFailReason), self::BAD)
                    . self::row('Uploads lost', (string) $uploadsDropped, self::BAD)
                : '')
            // Rows the server accepted the batch for and then refused. Shown
            // ONLY when the server actually reported some: an older server says
            // nothing, and a healthy app must not carry a permanent "0 dropped"
            // row implying loss is normal.
            . ($rowsDropped > 0 && $rowsDroppedText !== ''
                ? self::row('Measurements dropped', $rowsDroppedText, self::WARN)
                : '')
            // WHERE SCHEDULED JOBS LIVE. Always shown, never conditional: a
            // page that simply omits jobs reads as a page reporting there are
            // none. This kit reports no named job, and says so rather than
            // pointing at a call it does not export. Same answer as the in-app
            // page's jobs card; see
            // docs/decisions/kit-page-says-where-jobs-live.md.
            . self::row(
                'Scheduled jobs',
                "Not listed here, and this kit reports no named job at all. Background work reaches Boosthis only as the combined background-work meter, so your dashboard's scheduled-jobs list stays empty however many jobs this app runs."
            )
            . self::row('Requests measured', (string) $measured)
            . self::row(
                'Project key',
                (is_string($projectKeyDisplay) ? $projectKeyDisplay . ' — ' : '') . $projectKeySource
            )
            . self::row('Kit version', $kitVersion)
            . self::row('Runtime', $runtime)
            // Always shown because "nothing here declares a platform" is a
            // real answer, not a missing one. Amber means these numbers belong
            // to a throwaway deployment and must not be read as the real app's.
            . self::row(
                'Where it runs',
                $hostingLine,
                $hostingIsPreview ? self::WARN : null
            )
            . '</div>'
            . '<a class="dash" href="' . self::esc($dashboardUrl) . '" target="_blank" rel="noopener">'
            . 'Open your Boosthis dashboard</a>'
            . '<footer>This page is served by the Boosthis kit inside your own app, on '
            . 'your own port. It is only reachable from the machine running this app, '
            . 'and it never shows your install token.</footer>'
            . '</main></body></html>';
    }
}
