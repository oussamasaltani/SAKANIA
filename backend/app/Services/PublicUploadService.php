<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

/**
 * Writes files that have to be reachable by the web server.
 *
 * Everything stored under public/uploads is served straight off disk by the web
 * server (nginx `location /uploads`), which never passes through PHP. PHP-FPM and
 * nginx do not share a user: uploads are written as www-data (uid 33) and read by
 * the nginx workers (uid 101). Anything that is not world-readable in public/ is
 * therefore unreachable for the web server, which answers 403 even though the file
 * exists and the URL is correct.
 *
 * Permissions cannot be left to the process umask either: tempnam() creates its
 * file 0600 and rename() carries that mode over to the destination, so the result
 * is unreadable for "others" no matter how permissive the umask is. Every write
 * goes through this service so the mode is always set explicitly.
 */
class PublicUploadService
{
    /** rwxr-xr-x: the web server has to traverse and list the directory. */
    public const DIRECTORY_MODE = 0755;

    /** rw-r--r--: the web server only ever needs to read the file. */
    public const FILE_MODE = 0644;

    /**
     * Absolute path of a directory inside public/, created when it is missing.
     *
     * Existing directories are deliberately left alone. They can be owned by
     * another user (the bind mount that carries public/ reports root ownership on
     * a Windows host), and tightening their mode would revoke the write access
     * PHP-FPM needs in order to store anything in them.
     */
    public function directory(string $relativeDirectory): string
    {
        $absolute = public_path($relativeDirectory);

        if (! File::isDirectory($absolute)) {
            File::makeDirectory($absolute, self::DIRECTORY_MODE, true, true);
        }

        return $absolute;
    }

    /**
     * Writes contents to a public-relative path and returns that path, or null
     * when the bytes could not be written.
     */
    public function put(string $relativePath, string $contents): ?string
    {
        $absolute = $this->absolutePathFor($relativePath);

        if ($absolute === null || File::put($absolute, $contents) === false) {
            return null;
        }

        $this->applyMode($absolute, self::FILE_MODE);

        return $relativePath;
    }

    /**
     * Moves an already validated file into public/ and returns the public-relative
     * path, or null when the move failed.
     */
    public function move(string $absoluteSource, string $relativePath): ?string
    {
        $absolute = $this->absolutePathFor($relativePath);

        if ($absolute === null || ! @rename($absoluteSource, $absolute)) {
            return null;
        }

        $this->applyMode($absolute, self::FILE_MODE);

        return $relativePath;
    }

    /**
     * Resolves the destination path, making sure its directory exists first.
     */
    private function absolutePathFor(string $relativePath): ?string
    {
        $directory = dirname($relativePath);

        if ($directory === '.' || $directory === '') {
            return null;
        }

        return $this->directory($directory) . DIRECTORY_SEPARATOR . basename($relativePath);
    }

    private function applyMode(string $absolutePath, int $mode): bool
    {
        // The bytes are already on disk at this point; the mode only decides who
        // may read them. On mounts that do not track POSIX permissions chmod is a
        // no-op, which is harmless.
        return @chmod($absolutePath, $mode);
    }
}
