<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * The {@code devPosture} additive meter axis (PHP) — the parity sibling of the
 * shared cross-runtime "Dev Posture" axis (see the Java kit's
 * {@see \Boosthis\DevPosture} equivalent and the Go kit's readDevPosture). It
 * reports whether this live app is still wearing its development clothes:
 * settings that make sense on a laptop and leak information or attack surface
 * in production. Additive and display-only, exactly like {@see CookieExposure}
 * and {@see AccessPressure}; it NEVER feeds the Speed score.
 *
 * This meter reports EXPOSURE, never safety. A clean tile means "none of the
 * development settings we can read were on" — NEVER "you are production-
 * hardened". We only ever report the OBSERVED setting, never a fix.
 *
 * Read once per request, deterministically. PHP has no long-lived process: on
 * every request the interpreter reads its effective ini and loaded extensions
 * fresh. Unlike the Java/Go kits, which freeze the reads once at process start,
 * there is nothing here to freeze across requests — reading the live ini on the
 * request that renders the snapshot IS the honest, current answer. The reads
 * are pure ini_get / extension_loaded lookups with no side effects, so this is
 * safe on the hot path.
 *
 * The flag contract (the easy-to-get-wrong part). Every flag is present ONLY
 * when this runtime actually performed the check: value 1 means the development
 * setting is ON, value 0 means we read it and it was off. A check we cannot
 * read is OMITTED entirely — absence means "we did not look", never a 0 standing
 * in for it. {@code checks} counts present flags; {@code findings} counts the
 * ones that were on.
 *
 * Shared wire shape (byte-identical semantics across the server kits):
 * {@code { score, rating, caption, findings, checks, measurable, debugFlag?,
 * profilingOpen? }}. The shared flags {@code debugFlag} (any development/debug
 * facility active) and {@code profilingOpen} (a debugger/profiler attached and
 * reachable) carry PHP's readable signals. PHP-specific findings that have no
 * shared flag are surfaced as extra entries in the counts-only {@code caption}
 * and folded into {@code findings}/{@code checks}, exactly as the doctrine
 * allows ("add PHP-specific findings as new entries in the same finding list
 * shape") — they never invent a new wire flag, keeping the four-flag optional-
 * number contract the server's guard enforces intact.
 *
 * No PII. The payload carries numbers plus the {@code rating}/{@code caption}
 * strings only — never an ini VALUE, a path, a URL, or a config string. The
 * caption names counts and fixed setting NAMES only.
 */
final class DevPosture
{
    private function __construct()
    {
    }

    /** The axis key, shared with the Java/Go/Node/Python kits. */
    public const AXIS = 'devPosture';

    /**
     * Read one boolean-ish ini directive as a genuine on/off, or null when the
     * directive is not readable in this build. ini_get returns false only when
     * the directive does not exist; an existing-but-empty directive returns "".
     * PHP accepts "1", "on", "true", "yes" (case-insensitive) as on for the
     * boolean directives, and any non-zero numeric for the mask-style ones — we
     * fold both here so a value like "On" is not misread as off.
     */
    private static function iniOn(string $name): ?int
    {
        $raw = ini_get($name);
        if ($raw === false) {
            return null; // directive not present in this build — do not look
        }
        $v = strtolower(trim((string) $raw));
        if ($v === '') {
            return 0;
        }
        $on = $v === '1' || $v === 'on' || $v === 'true' || $v === 'yes'
            || (is_numeric($v) && (float) $v != 0.0);

        return $on ? 1 : 0;
    }

    /**
     * The {@code devPosture} axis as a closed map, or {@code null} when NO check
     * was readable this request (honest absence — the axis is simply omitted).
     * In practice at least one directive is always readable on PHP, so the axis
     * emits with {@code measurable = 1}. The ONLY string fields are
     * {@code rating} and {@code caption}; every other field is a number.
     *
     * @return array<string,int|string>|null
     */
    public static function read(): ?array
    {
        if (RuntimeFlags::disabled()) {
            return null;
        }

        return Safe::run(static function (): ?array {
            // ── Shared flag: debugFlag ────────────────────────────────────────
            // "Development/debug facility active". On PHP the clearest single
            // signal is Xdebug's develop or debug mode being on: that turns on
            // verbose stack traces, variable dumps in errors, and step-debugging
            // wiring — the exact "still in dev clothes" tell this axis exists to
            // catch. We treat Xdebug-with-a-develop/debug-mode as debugFlag on
            // (severe), and fall through to display_errors as the debug signal
            // when Xdebug is absent, since verbose error output is the other
            // facility that says "not configured for production".
            $debugPresent = false;
            $debugOn = false;

            $xdebugLoaded = extension_loaded('xdebug');
            if ($xdebugLoaded) {
                $debugPresent = true;
                // xdebug.mode is comma-separated (e.g. "develop,debug"). The
                // develop and debug modes are the ones that leak/step; "off",
                // "coverage", "profile", "trace", "gcstats" are not this flag.
                $mode = ini_get('xdebug.mode');
                if ($mode !== false) {
                    $modes = array_map('trim', explode(',', strtolower((string) $mode)));
                    if (in_array('develop', $modes, true) || in_array('debug', $modes, true)) {
                        $debugOn = true;
                    }
                }
            }
            // display_errors also stands in for the debug facility when Xdebug is
            // not present, so a plain PHP app in dev still trips debugFlag.
            $displayErrors = self::iniOn('display_errors');
            if ($displayErrors !== null) {
                $debugPresent = true;
                if ($displayErrors === 1) {
                    $debugOn = true;
                }
            }

            // ── Shared flag: profilingOpen ────────────────────────────────────
            // "A debugger/profiler attached and reachable". On PHP that is
            // Xdebug wired for step-debugging or profiling: xdebug.mode contains
            // "debug" (a DBGP client can attach) or "profile" (cachegrind
            // profiling is emitting). We can only assert this when Xdebug is
            // loaded; without it there is nothing to read, so the flag is OMITTED
            // (honest absence) rather than reported as a 0.
            $profilingPresent = false;
            $profilingOn = false;
            if ($xdebugLoaded) {
                $profilingPresent = true;
                $mode = ini_get('xdebug.mode');
                if ($mode !== false) {
                    $modes = array_map('trim', explode(',', strtolower((string) $mode)));
                    if (in_array('debug', $modes, true) || in_array('profile', $modes, true)) {
                        $profilingOn = true;
                    }
                }
            }

            // ── PHP-specific findings (no shared wire flag) ───────────────────
            // Each is a genuine "development clothes" ini we can read directly.
            // They fold into findings/checks and name themselves in the caption,
            // but never mint a new wire flag (the server guard enforces the four
            // optional flags only). Each is present only when readable.
            // A short list of [caption label, on?] pairs; null on? = unreadable.
            $extras = [];

            // display_startup_errors: startup-phase errors printed to output.
            $extras['startup errors shown'] = self::iniOn('display_startup_errors');
            // expose_php: advertises "X-Powered-By: PHP/x.y" — a free version
            // banner for an attacker. On in a live app is a dev/default leftover.
            $extras['php version exposed'] = self::iniOn('expose_php');
            // error_reporting including E_ALL: maximal error verbosity, a dev
            // default. We read the effective mask and check the E_ALL bits are
            // all set (production usually trims notices/deprecations).
            $er = self::readErrorReportingEAll();
            $extras['reporting all errors'] = $er;
            // assert.active + zend.assertions>=1: runtime assertions executing,
            // which is a development-only cost/behaviour. Report as one finding
            // when BOTH say assertions run (zend.assertions is the master switch;
            // -1 = compiled out, 0 = compiled-in-but-off, 1 = active).
            $extras['assertions active'] = self::readAssertionsActive();
            // allow_url_include: lets include/require pull from a URL — a serious
            // remote-code exposure that is off by default and only ever turned on
            // in careless dev setups.
            $extras['url include allowed'] = self::iniOn('allow_url_include');
            // opcache disabled while serving under FPM/Apache: the bytecode cache
            // off in a real SAPI is the "still running like a dev box" tell. Only
            // meaningful under a server SAPI, so it self-gates on the SAPI name.
            $extras['opcache off under server'] = self::readOpcacheOffUnderServer();

            // ── Assemble ─────────────────────────────────────────────────────
            $checks = 0;
            $findings = 0;

            $debugFlag = null;
            if ($debugPresent) {
                $checks++;
                $debugFlag = $debugOn ? 1 : 0;
                if ($debugOn) {
                    $findings++;
                }
            }
            $profilingFlag = null;
            if ($profilingPresent) {
                $checks++;
                $profilingFlag = $profilingOn ? 1 : 0;
                if ($profilingOn) {
                    $findings++;
                }
            }

            // Fold the PHP-specific readable checks into checks/findings.
            $extraOn = [];
            foreach ($extras as $label => $flag) {
                if ($flag === null) {
                    continue; // unreadable — did not look
                }
                $checks++;
                if ($flag === 1) {
                    $findings++;
                    $extraOn[] = $label;
                }
            }

            if ($checks === 0) {
                return null; // honest absence — could read nothing this request
            }

            // Score: start 100; -60 when debugFlag is on (the severe signal);
            // -20 for EACH other finding; clamp 0..100. Deterministic, matching
            // the shared severity model (Score = 100 - 60*debugFlag - 20*others).
            $score = 100;
            $otherFindings = $findings;
            $debugIsOn = $debugFlag === 1;
            if ($debugIsOn) {
                $score -= 60;
                $otherFindings--;
            }
            $score -= 20 * $otherFindings;
            if ($score < 0) {
                $score = 0;
            }
            if ($score > 100) {
                $score = 100;
            }

            // Rating: debugFlag on → poor; any other finding → needs-work; else
            // good, worded as an absence (never "hardened").
            if ($debugIsOn) {
                $rating = Score::POOR;
            } elseif ($findings > 0) {
                $rating = Score::NEEDS_WORK;
            } else {
                $rating = Score::GOOD;
            }

            $axis = [];
            $axis['score'] = $score;
            $axis['rating'] = $rating;
            if ($debugFlag !== null) {
                $axis['debugFlag'] = $debugFlag;
            }
            if ($profilingFlag !== null) {
                $axis['profilingOpen'] = $profilingFlag;
            }
            $axis['findings'] = $findings;
            $axis['checks'] = $checks;
            $axis['measurable'] = 1;
            $axis['caption'] = self::caption($checks, $debugFlag, $profilingFlag, $extraOn);

            return $axis;
        }, null);
    }

    /**
     * True(1)/false(0) for "error_reporting includes the full E_ALL mask", or
     * null if it cannot be read. Reads the EFFECTIVE level via error_reporting()
     * with no argument (does not change it), and compares against E_ALL.
     */
    private static function readErrorReportingEAll(): ?int
    {
        $level = error_reporting();
        if (!is_int($level)) {
            return null;
        }

        return ($level & E_ALL) === E_ALL ? 1 : 0;
    }

    /**
     * True(1) when runtime assertions actually execute: zend.assertions must be
     * 1 (active — not -1 "compiled out" or 0 "off") AND assert.active on. Null
     * only when neither directive is readable.
     */
    private static function readAssertionsActive(): ?int
    {
        $zend = ini_get('zend.assertions');
        $active = self::iniOn('assert.active');
        if ($zend === false && $active === null) {
            return null;
        }
        // zend.assertions is the master switch: -1 compiled out, 0 off, 1 active.
        $zendActive = $zend !== false && (int) $zend >= 1;
        // assert.active defaults on; treat an unreadable assert.active as on so a
        // build that only exposes zend.assertions still reports honestly.
        $assertActive = $active === null ? true : $active === 1;

        return ($zendActive && $assertActive) ? 1 : 0;
    }

    /**
     * True(1) when OPcache is disabled while running under a server SAPI
     * (php-fpm, apache2handler, cli-server, etc.) — the bytecode cache off in a
     * live server is the development leftover. Null when this is not a server
     * SAPI (CLI has no OPcache expectation) so it self-gates and is simply not a
     * finding there.
     */
    private static function readOpcacheOffUnderServer(): ?int
    {
        $sapi = strtolower((string) php_sapi_name());
        // CLI and phpdbg have no server-side opcache expectation — do not look.
        if ($sapi === 'cli' || $sapi === 'phpdbg' || $sapi === '') {
            return null;
        }
        // The most honest read is the runtime status; fall back to the ini flag.
        if (function_exists('opcache_get_status')) {
            $status = @opcache_get_status(false);
            if (is_array($status)) {
                $enabled = (bool) ($status['opcache_enabled'] ?? false);

                return $enabled ? 0 : 1;
            }
        }
        $enable = self::iniOn('opcache.enable');
        if ($enable === null) {
            return null; // extension not present — nothing to report
        }

        return $enable === 1 ? 0 : 1;
    }

    /**
     * Counts-and-NAMES-only caption. A clean tile is phrased as an absence of
     * observed development settings, never as "hardened". Never quotes an ini
     * value, path, or config string.
     *
     * @param list<string> $extraOn labels of PHP-specific findings that are on
     */
    private static function caption(
        int $checks,
        ?int $debugFlag,
        ?int $profilingFlag,
        array $extraOn
    ): string {
        $on = [];
        if ($debugFlag === 1) {
            $on[] = 'debug mode on';
        }
        if ($profilingFlag === 1) {
            $on[] = 'profiling port open';
        }
        foreach ($extraOn as $label) {
            $on[] = $label;
        }
        if ($on === []) {
            return 'none of the ' . $checks . ' development settings we can read were on';
        }

        return implode(', ', $on);
    }
}
