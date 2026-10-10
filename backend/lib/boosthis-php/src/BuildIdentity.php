<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * What this build IS: the app's own name, the PHP it runs on, the framework it
 * uses, and how old its dependency set is.
 *
 * Everything here is honest-or-absent. A component we cannot read is left out
 * of the payload entirely — never guessed, never defaulted to a plausible
 * value. That is what makes the exposure-window and patch-lag meters worth
 * anything.
 */
final class BuildIdentity
{
    private function __construct()
    {
    }

    /**
     * Best-effort app name for a project that did not set one: the composer
     * package name without its vendor prefix, else the project directory name.
     * Explicit configuration always wins over this.
     */
    public static function detectAppName(): ?string
    {
        return Safe::run(static function (): ?string {
            $composer = self::composerJson();
            $name = $composer['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $parts = explode('/', $name);
                $short = end($parts);
                if (is_string($short) && $short !== '') {
                    return substr($short, 0, 60);
                }
            }
            $dir = basename(Store::projectRoot());

            return $dir !== '' && $dir !== '/' ? substr($dir, 0, 60) : null;
        });
    }

    /** @return array<string,mixed> */
    private static function composerJson(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = Safe::run(static function (): array {
            $path = Store::projectRoot() . '/composer.json';
            if (!is_file($path)) {
                return [];
            }
            $raw = @file_get_contents($path);

            return Json::decode($raw === false ? null : $raw) ?? [];
        }, []) ?? [];

        return $cache;
    }

    /** @return array<string,mixed> */
    private static function composerLock(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = Safe::run(static function (): array {
            $path = Store::projectRoot() . '/composer.lock';
            if (!is_file($path)) {
                return [];
            }
            $raw = @file_get_contents($path);

            return Json::decode($raw === false ? null : $raw) ?? [];
        }, []) ?? [];

        return $cache;
    }

    /**
     * Which framework is serving this request, when we can tell from classes
     * the host already loaded. No file scanning, no guessing from directory
     * names — a wrong answer here is worse than no answer.
     */
    public static function framework(): ?string
    {
        return Safe::run(static function (): ?string {
            if (defined('WPINC') || function_exists('wp_get_environment_type')) {
                return 'wordpress';
            }
            if (class_exists('\Illuminate\Foundation\Application', false)) {
                return 'laravel';
            }
            if (class_exists('\Symfony\Component\HttpKernel\Kernel', false)) {
                return 'symfony';
            }
            if (class_exists('\Slim\App', false)) {
                return 'slim';
            }
            if (class_exists('\Laminas\Mvc\Application', false)) {
                return 'laminas';
            }
            if (class_exists('\CodeIgniter\CodeIgniter', false)) {
                return 'codeigniter';
            }

            return null;
        });
    }

    /** How PHP is being served: fpm-fcgi, cli-server, apache2handler, cli, ... */
    public static function sapi(): string
    {
        return PHP_SAPI;
    }

    /**
     * The build block for the snapshot: emitted only when at least one
     * component is genuinely known.
     *
     * @return array<string,mixed>|null
     */
    public static function buildInfo(): ?array
    {
        return Safe::run(static function (): ?array {
            $info = [];
            $info['runtimeName'] = 'php';
            $info['runtimeVersion'] = PHP_VERSION;
            $info['sapi'] = self::sapi();
            $fw = self::framework();
            if ($fw !== null) {
                $info['framework'] = $fw;
            }
            $fwVersion = self::frameworkVersion($fw);
            if ($fwVersion !== null) {
                $info['frameworkVersion'] = $fwVersion;
            }
            $lockAge = self::lockAgeDays();
            if ($lockAge !== null) {
                $info['lockAgeDays'] = $lockAge;
            }
            $count = self::dependencyCount();
            if ($count !== null) {
                $info['dependencyCount'] = $count;
            }

            return $info === [] ? null : $info;
        });
    }

    private static function frameworkVersion(?string $framework): ?string
    {
        if ($framework === null) {
            return null;
        }

        return Safe::run(static function () use ($framework): ?string {
            if ($framework === 'laravel'
                && class_exists('\Illuminate\Foundation\Application', false)) {
                $const = '\Illuminate\Foundation\Application::VERSION';

                return defined($const) ? (string) constant($const) : null;
            }
            if ($framework === 'symfony'
                && class_exists('\Symfony\Component\HttpKernel\Kernel', false)) {
                $const = '\Symfony\Component\HttpKernel\Kernel::VERSION';

                return defined($const) ? (string) constant($const) : null;
            }
            if ($framework === 'wordpress') {
                global $wp_version;

                return is_string($wp_version ?? null) ? $wp_version : null;
            }

            return null;
        });
    }

    /** How many days since composer.lock was last written. Null when unknown. */
    public static function lockAgeDays(): ?int
    {
        return Safe::run(static function (): ?int {
            $path = Store::projectRoot() . '/composer.lock';
            if (!is_file($path)) {
                return null;
            }
            $mtime = @filemtime($path);
            if ($mtime === false) {
                return null;
            }
            $days = (int) floor((time() - $mtime) / 86400);

            return $days >= 0 ? $days : null;
        });
    }

    public static function dependencyCount(): ?int
    {
        $lock = self::composerLock();
        if ($lock === []) {
            return null;
        }
        $packages = $lock['packages'] ?? null;

        return is_array($packages) ? count($packages) : null;
    }

    /**
     * Dependency inventory — OPT-IN, OFF BY DEFAULT
     * (BOOSTHIS_DEP_INVENTORY=1). When off this returns null and no `deps`
     * field is emitted at all. Names and versions only: no paths, no sources.
     *
     * @return list<array{name:string,version:string}>|null
     */
    public static function deps(): ?array
    {
        if (!RuntimeFlags::depInventory()) {
            return null;
        }

        return Safe::run(static function (): ?array {
            $lock = self::composerLock();
            $packages = $lock['packages'] ?? null;
            if (!is_array($packages)) {
                return null;
            }
            $out = [];
            foreach ($packages as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $name = $p['name'] ?? null;
                $version = $p['version'] ?? null;
                if (is_string($name) && is_string($version)) {
                    $out[] = ['name' => $name, 'version' => $version];
                }
                if (count($out) >= 300) {
                    break;
                }
            }

            return $out === [] ? null : $out;
        });
    }

    // ── patchLag ────────────────────────────────────────────────────────────

    /** Shared cross-runtime rating bands (days since the build was cut). */
    private const PATCH_LAG_GOOD_DAYS = 7;
    private const PATCH_LAG_NEEDS_WORK_DAYS = 30;
    /** The age (days) at which the score reaches 0. */
    private const PATCH_LAG_SCALE_DAYS = 60;
    private const MS_PER_DAY = 86400000;

    /**
     * The {@code patchLag} axis — how long this deployment has been running the
     * code it was deployed with. Emitted ONLY when the build time is knowable
     * (null = honest absence, never a fake or forever-warming axis).
     *
     * PHP-SPECIFIC SOURCE. A PHP app is not compiled, so there is no binary
     * build stamp the way Go and Java have one. The honest equivalent is when
     * this deployment's dependency set was last resolved: composer.lock's
     * mtime, which moves on every `composer install`/`update` — i.e. on every
     * real deploy. Hosts without a composer.lock (a hand-vendored WordPress
     * tree, say) get no axis at all rather than a guess.
     *
     * ADDITIVE + display-only: NEVER feeds the Speed score. Wire keys:
     * {score, rating, buildAgeMs, buildTimeMs, depCount?} — depCount only when
     * the dependency-inventory opt-in is ON. Never throws.
     *
     * @return array<string,mixed>|null
     */
    public static function readPatchLag(): ?array
    {
        if (RuntimeFlags::disabled()) {
            return null;
        }

        return Safe::run(static function (): ?array {
            $path = Store::projectRoot() . '/composer.lock';
            if (!is_file($path)) {
                return null;
            }
            $mtime = @filemtime($path);
            if ($mtime === false) {
                return null;
            }
            $buildTimeMs = $mtime * 1000;
            $ageMs = (int) (time() * 1000) - $buildTimeMs;
            if ($ageMs < 0) {
                $ageMs = 0;
            }
            $ageDays = $ageMs / self::MS_PER_DAY;
            if ($ageDays < self::PATCH_LAG_GOOD_DAYS) {
                $rating = Score::GOOD;
            } elseif ($ageDays < self::PATCH_LAG_NEEDS_WORK_DAYS) {
                $rating = Score::NEEDS_WORK;
            } else {
                $rating = Score::POOR;
            }
            $raw = 100 - ($ageDays / self::PATCH_LAG_SCALE_DAYS) * 100;
            $raw = max(0.0, min(100.0, $raw));
            $axis = [
                'score' => (int) round($raw),
                'rating' => $rating,
                'buildAgeMs' => $ageMs,
                'buildTimeMs' => $buildTimeMs,
                // Caption reports build AGE ONLY — never implies patched/safe.
                'caption' => 'build ~' . (int) $ageDays . 'd old',
            ];
            $deps = self::deps();
            if ($deps !== null) {
                $axis['depCount'] = count($deps);
            }

            return $axis;
        });
    }
}
