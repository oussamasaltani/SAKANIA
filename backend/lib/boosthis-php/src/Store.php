<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * Cross-request state.
 *
 * PHP is the first Boosthis runtime with no long-lived process to hold state
 * in: under PHP-FPM or mod_php every request starts from nothing, so anything
 * a meter needs to remember between requests has to live outside the process.
 * That single fact shapes this whole kit. Store is the one place that knows
 * how, and every meter goes through it.
 *
 * Two backends, chosen automatically:
 *   - APCu when the extension is present and enabled (shared across the
 *     worker pool, in memory, no disk I/O in the request path);
 *   - a file per key otherwise, with an exclusive lock for read-modify-write.
 *
 * SCOPE IS PER PROJECT, never machine-wide. Two PHP services on one host must
 * not blend their meters, and "forget everything" has to be able to sweep every
 * scope it created. The scope key is derived from the project root path, so it
 * is stable across restarts and distinct per app.
 *
 * OWNER-ONLY ON DISK. The scope directory's name is a truncated hash of the
 * project root — public information, never a secret — and the files inside it
 * hold this install's bearer credentials: the delete token authorizes ingest
 * and forget, the read token authorizes reads of this install's private
 * telemetry. On a shared host a 0755 directory or a 0644 state file hands both
 * to every other local account. Asking mkdir() for 0700 is not enough on its
 * own: the path is predictable, so a local attacker can pre-create it
 * permissively and the mkdir simply does nothing. So an existing directory is
 * inspected and REPAIRED, the repair is VERIFIED, every state file is written
 * 0600, and a directory that cannot be made owner-only switches the file
 * backend off rather than publishing the tokens.
 */
final class Store
{
    private const PREFIX = 'boosthis:';
    private const DIR_PREFIX = 'boosthis-';

    /** Owner-only modes; any group/other bit is too much for a bearer token. */
    private const DIR_MODE = 0o700;
    private const FILE_MODE = 0o600;
    private const SHARED_BITS = 0o077;

    private static ?string $scopeDir = null;
    private static bool $scopeDirPrivate = false;
    private static ?string $scopeDirFailure = null;
    private static ?string $scopeKey = null;
    private static ?bool $apcu = null;

    private function __construct()
    {
    }

    /** The project root this kit is instrumenting. */
    public static function projectRoot(): string
    {
        // vendor/boosthis/boosthis-php/src/Store.php -> up four levels is the
        // host project root for a composer install; for a vendored copy the
        // same walk lands on the directory holding the kit. Never fall back to
        // the process's cwd: an ordinary restart from another directory must
        // retain the same state scope.
        return dirname(__DIR__, 4);
    }

    public static function scopeKey(): string
    {
        if (self::$scopeKey === null) {
            self::$scopeKey = substr(sha1(self::projectRoot()), 0, 16);
        }

        return self::$scopeKey;
    }

    /** Directory holding this project's state files. Created on first use. */
    public static function dir(): string
    {
        if (self::$scopeDir !== null) {
            return self::$scopeDir;
        }
        $base = RuntimeFlags::stateDir() ?? sys_get_temp_dir();
        $dir = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . self::DIR_PREFIX . self::scopeKey();
        self::$scopeDirPrivate = self::prepareDir($dir);
        self::$scopeDir = $dir;

        return $dir;
    }

    /**
     * The scope directory ONLY when it is owner-only, i.e. safe to keep this
     * install's tokens in. Every file read and write goes through this; null
     * switches the file backend off. State we refuse to write is also state we
     * must not read back — otherwise a pre-created shared directory becomes a
     * way to feed the kit someone else's install id and tokens.
     */
    private static function durableDir(): ?string
    {
        $dir = self::dir();

        return self::$scopeDirPrivate ? $dir : null;
    }

    /**
     * Windows has no POSIX modes and chmod() there is a no-op, so the checks
     * below would refuse every directory. Skip them off POSIX.
     */
    private static function posixModes(): bool
    {
        return DIRECTORY_SEPARATOR === '/';
    }

    /**
     * Make a scope directory fit to hold credentials: owner-only, with any file
     * an older kit already wrote there tightened too.
     */
    private static function prepareDir(string $dir): bool
    {
        self::$scopeDirFailure = null;
        if (!self::ensurePrivateDir($dir)) {
            return false;
        }
        self::hardenExistingFiles($dir);

        return true;
    }

    /**
     * Create $dir owner-only, repairing one an older kit (or a local attacker)
     * left group/world accessible. The repair is checked, never assumed: chmod
     * on a directory owned by another local account fails, and that is
     * precisely the case — a permissive directory pre-created at the
     * predictable scope path — where writing tokens there would leak them.
     */
    private static function ensurePrivateDir(string $dir): bool
    {
        if (!is_dir($dir)) {
            // Suppressed: a read-only or full filesystem must degrade to "no
            // cross-request meters", never to an exception in the host.
            @mkdir($dir, self::DIR_MODE, true);
        }
        if (!is_dir($dir)) {
            self::$scopeDirFailure = 'no writable state directory on this host';
            return false;
        }
        if (!self::posixModes()) {
            return true;
        }
        // A symlink planted at the predictable path points somewhere we never
        // chose, so it is never our scope directory.
        if (is_link($dir)) {
            self::$scopeDirFailure = 'the state directory cannot be made owner-only';
            return false;
        }
        clearstatcache(true, $dir);
        $mode = @fileperms($dir);
        if ($mode === false) {
            self::$scopeDirFailure = 'the state directory cannot be made owner-only';
            return false;
        }
        if (($mode & self::SHARED_BITS) === 0) {
            return true;
        }
        @chmod($dir, self::DIR_MODE);
        clearstatcache(true, $dir);
        $mode = @fileperms($dir);

        $private = $mode !== false && ($mode & self::SHARED_BITS) === 0;
        if (!$private) {
            self::$scopeDirFailure = 'the state directory cannot be made owner-only';
        }

        return $private;
    }

    /**
     * Tighten state files an older kit already wrote at 0644. A file that is
     * never written again would otherwise keep its readable mode forever.
     */
    private static function hardenExistingFiles(string $dir): void
    {
        if (!self::posixModes()) {
            return;
        }
        foreach ((glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: []) as $f) {
            if (is_link($f) || !is_file($f)) {
                continue;
            }
            self::hardenFile($f);
        }
    }

    /** Make one state file owner-only. */
    private static function hardenFile(string $path): void
    {
        if (!self::posixModes() || is_link($path) || !is_file($path)) {
            return;
        }
        clearstatcache(true, $path);
        $mode = @fileperms($path);
        if ($mode !== false && ($mode & self::SHARED_BITS) !== 0) {
            @chmod($path, self::FILE_MODE);
        }
    }

    private static function apcuAvailable(): bool
    {
        if (self::$apcu === null) {
            self::$apcu = function_exists('apcu_fetch')
                && function_exists('apcu_enabled')
                && @apcu_enabled();
        }

        return self::$apcu;
    }

    private static function apcuKey(string $key): string
    {
        return self::PREFIX . self::scopeKey() . ':' . $key;
    }

    /**
     * Which backing store this process actually got. Reported as-is in the
     * status output: a developer debugging "why are my meters empty?" needs to
     * know whether anything can be kept between requests at all.
     */
    public static function backend(): string
    {
        if (self::apcuAvailable()) {
            return 'apcu';
        }

        return self::isWritable() ? 'file' : 'none';
    }

    /**
     * Can state survive this request? False means every meter starts cold —
     * which includes a scope directory we could not make owner-only, because
     * credentials are never written into a shared one.
     */
    public static function isWritable(): bool
    {
        if (self::apcuAvailable()) {
            return true;
        }
        $dir = self::durableDir();

        return $dir !== null && is_dir($dir) && is_writable($dir);
    }

    /**
     * Identity-specific disk truth. APCu is deliberately excluded: it is
     * process-local and cannot prove a restart will see the same install.
     *
     * @return array{disk:bool,restored:bool}
     */
    public static function identityDiskInfo(string $key, ?string $expectedId = null): array
    {
        if (self::apcuAvailable()) {
            return ['disk' => false, 'restored' => false];
        }
        $dir = self::durableDir();
        if ($dir === null || !is_writable($dir)) {
            return ['disk' => false, 'restored' => false];
        }
        $path = self::path($key);
        if ($path === null || !is_file($path)) {
            return ['disk' => false, 'restored' => false];
        }
        $decoded = json_decode((string) @file_get_contents($path), true);
        $restored = $expectedId !== null && is_array($decoded)
            && ($decoded['installId'] ?? null) === $expectedId;

        return ['disk' => $restored, 'restored' => $restored];
    }

    /** Why this process cannot safely keep identity, from the closed notice set. */
    public static function identityPersistenceCause(): ?string
    {
        if (self::apcuAvailable()) {
            return null;
        }
        self::dir();
        if (self::$scopeDirFailure !== null) {
            return self::$scopeDirFailure;
        }

        return self::isWritable() ? null : 'no writable state directory on this host';
    }

    private static function fileNameFor(string $key): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $key) . '.json';
    }

    /**
     * Where a key's file lives, whatever the directory's permissions are. Used
     * by removal only — sweeping a directory we refuse to write into is still
     * right.
     */
    private static function anyPath(string $key): string
    {
        return self::dir() . DIRECTORY_SEPARATOR . self::fileNameFor($key);
    }

    /** Where a key's file lives when the directory is owner-only. */
    private static function path(string $key): ?string
    {
        $dir = self::durableDir();

        return $dir === null ? null : $dir . DIRECTORY_SEPARATOR . self::fileNameFor($key);
    }

    /**
     * Read a value. Returns $default when missing, unreadable or corrupt —
     * corrupt state is treated as absent, never as a reason to throw.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (RuntimeFlags::disabled()) {
            return $default;
        }

        return Safe::run(static function () use ($key, $default) {
            if (self::apcuAvailable()) {
                $hit = false;
                $val = apcu_fetch(self::apcuKey($key), $hit);

                return $hit ? $val : $default;
            }
            $path = self::path($key);
            if ($path === null || !is_file($path)) {
                return $default;
            }
            $raw = @file_get_contents($path);
            if ($raw === false || $raw === '') {
                return $default;
            }
            $decoded = json_decode($raw, true);

            return $decoded === null ? $default : $decoded;
        }, $default);
    }

    /** Write a value. Silently does nothing when state is unavailable. */
    public static function set(string $key, mixed $value, int $ttlSeconds = 0): void
    {
        if (RuntimeFlags::disabled()) {
            return;
        }
        Safe::fire(static function () use ($key, $value, $ttlSeconds): void {
            if (self::apcuAvailable()) {
                apcu_store(self::apcuKey($key), $value, $ttlSeconds);

                return;
            }
            $encoded = Json::encode($value);
            if ($encoded === null) {
                return;
            }
            $path = self::path($key);
            if ($path === null) {
                return;
            }
            $tmp = $path . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, $encoded, LOCK_EX) === false) {
                return;
            }
            // Owner-only before the file is visible under its real name. PHP
            // has no create-mode argument, so the chmod follows the write; the
            // directory is already 0700, which is what keeps the gap shut.
            self::hardenFile($tmp);
            // Atomic replace so a concurrent reader never sees a half-written
            // file. The renamed file keeps the temporary's owner-only mode.
            if (!@rename($tmp, $path)) {
                @unlink($tmp);
            }
        });
    }

    /**
     * Read-modify-write under an exclusive lock. $fn receives the current value
     * (or $default) and returns the new one. This is how every counter and
     * rolling window in the kit is updated — concurrent FPM workers hitting the
     * same key must not lose increments.
     *
     * @param callable(mixed):mixed $fn
     */
    public static function update(string $key, callable $fn, mixed $default = null): mixed
    {
        if (RuntimeFlags::disabled()) {
            return $default;
        }

        return Safe::run(static function () use ($key, $fn, $default) {
            if (self::apcuAvailable()) {
                // APCu has no general CAS for arbitrary values; the entry lock
                // below serializes writers within the pool. Losing a rare race
                // costs one sample, never correctness of the stored shape.
                $akey = self::apcuKey($key);
                $hit = false;
                $cur = apcu_fetch($akey, $hit);
                $next = $fn($hit ? $cur : $default);
                apcu_store($akey, $next);

                return $next;
            }
            $path = self::path($key);
            if ($path === null) {
                return $default;
            }
            $fh = @fopen($path, 'c+');
            if ($fh === false) {
                return $default;
            }
            // 'c+' creates through the umask, so a brand-new counter file
            // starts 0644. Tighten it before anything is written into it.
            self::hardenFile($path);
            try {
                if (!@flock($fh, LOCK_EX)) {
                    return $default;
                }
                $raw = stream_get_contents($fh);
                $cur = ($raw === false || $raw === '') ? $default : json_decode($raw, true);
                if ($cur === null) {
                    $cur = $default;
                }
                $next = $fn($cur);
                $encoded = Json::encode($next);
                if ($encoded !== null) {
                    ftruncate($fh, 0);
                    rewind($fh);
                    fwrite($fh, $encoded);
                    fflush($fh);
                }
                @flock($fh, LOCK_UN);

                return $next;
            } finally {
                fclose($fh);
            }
        }, $default);
    }

    public static function delete(string $key): void
    {
        Safe::fire(static function () use ($key): void {
            if (self::apcuAvailable()) {
                apcu_delete(self::apcuKey($key));
            }
            $path = self::anyPath($key);
            if (is_file($path)) {
                @unlink($path);
            }
        });
    }

    /**
     * Erase every trace of this project's state — both backends, both scopes.
     * Called by forget()/purge. It deliberately clears the file backend even
     * when APCu is active: a host that switched backends mid-life must not be
     * left with a stale copy on disk.
     */
    public static function forgetAll(): void
    {
        Safe::fire(static function (): void {
            if (self::apcuAvailable() && class_exists('\APCuIterator')) {
                $prefix = preg_quote(self::PREFIX . self::scopeKey() . ':', '/');
                /** @var iterable<array{key:string}> $it */
                $it = new \APCuIterator('/^' . $prefix . '/');
                foreach ($it as $entry) {
                    apcu_delete($entry['key']);
                }
            }
            $dir = self::dir();
            if (is_dir($dir)) {
                foreach ((glob($dir . DIRECTORY_SEPARATOR . '*') ?: []) as $f) {
                    if (is_file($f)) {
                        @unlink($f);
                    }
                }
                @rmdir($dir);
            }
            self::$scopeDir = null;
            self::$scopeDirPrivate = false;
            self::$scopeDirFailure = null;
        });
    }

    /** Test seam: point the store at a throwaway directory. */
    public static function useDirectoryForTests(string $dir): void
    {
        self::$scopeDirPrivate = self::prepareDir($dir);
        self::$scopeDir = $dir;
        self::$apcu = false;
    }
}
