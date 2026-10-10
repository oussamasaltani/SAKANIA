<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

/**
 * Decodes the base64 images the client sends and publishes them as real files.
 *
 * The whole application ships images this way — the browser reads a picked file
 * with FileReader, sends a `data:image/...;base64,...` string in the JSON body,
 * and the server turns it into a file under public/ that nginx serves directly.
 * No multipart upload and no Storage disk is involved anywhere, so this is the
 * only upload path the project has.
 *
 * The bytes are never trusted: the declared MIME type is only used to recognise
 * the data URI header, the real type comes from getimagesize() on the decoded
 * file, and the extension is taken from that result. A renamed .php or a text
 * file carrying an image MIME header therefore never reaches public/.
 */
class ImageUploadService
{
    /** Ceiling for a profile picture: large enough for any camera, small enough
     *  to stay well inside the nginx client_max_body_size once base64 inflated. */
    public const PROFILE_MAX_BYTES = 2 * 1024 * 1024;

    /** Property photos, which the owner upload form has always accepted. */
    public const PROPERTY_MAX_BYTES = 5 * 1024 * 1024;

    private const EXTENSIONS = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_AVIF => 'avif',
        IMAGETYPE_BMP => 'bmp',
    ];

    public function __construct(private readonly PublicUploadService $public)
    {
    }

    /**
     * Stores a data URI below the given public-relative directory and returns the
     * stored path, or null when the input is not a usable image.
     *
     * @param  int  $maxBytes  Largest accepted decoded image.
     */
    public function storeDataUri(?string $dataUri, string $directory, int $maxBytes = self::PROPERTY_MAX_BYTES): ?string
    {
        if ($dataUri === null || $dataUri === '') {
            return null;
        }

        // The header only has to *look* like a base64 image data URI. Modern
        // browsers and phones label photos image/avif or image/heic, and some
        // file pickers hand over an empty type, so pinning this to a short list
        // used to discard perfectly valid uploads before they were ever decoded.
        // The real type is decided by getimagesize() below, so widening this
        // match cannot let a non-image through.
        if (! preg_match('/^data:(?:image\/[\w.+-]+)?;base64,(.*)$/is', $dataUri, $matches)) {
            return null;
        }

        // base64 inflates by ~4/3; refuse an obviously oversized payload before
        // spending memory on decoding it.
        if (strlen($matches[1]) > (int) ($maxBytes * 4 / 3) + 1024) {
            return null;
        }

        $data = base64_decode($matches[1], true);

        if ($data === false || $data === '' || strlen($data) > $maxBytes) {
            return null;
        }

        $temporary = tempnam(sys_get_temp_dir(), 'sakania_');

        if ($temporary === false) {
            return null;
        }

        file_put_contents($temporary, $data);

        $info = @getimagesize($temporary);
        $type = $info[2] ?? null;

        if (! isset(self::EXTENSIONS[$type])) {
            @unlink($temporary);

            return null;
        }

        // A fresh name on every upload: nginx serves /uploads with a 30 day
        // immutable cache, so reusing a filename would keep serving the old image.
        $relativePath = rtrim($directory, '/') . '/' . time() . '_' . uniqid('', true) . '.' . self::EXTENSIONS[$type];

        // tempnam() creates the file 0600 and rename() keeps that mode, so the
        // upload has to go through PublicUploadService to end up readable by the
        // nginx workers that serve public/.
        $stored = $this->public->move($temporary, $relativePath);

        if ($stored === null) {
            @unlink($temporary);

            return null;
        }

        return $stored;
    }

    /**
     * Removes a previously stored upload.
     *
     * Only files that live inside the given directory are deleted, and only when
     * they are stored under public/uploads. The database column is writable by
     * the user through the profile endpoint, so a value such as
     * `../../.env` must never turn into an unlink() of an arbitrary path.
     */
    public function deleteStored(string $relativePath, string $directory): bool
    {
        if ($relativePath === '' || str_contains($relativePath, '..') || str_contains($relativePath, "\0")) {
            return false;
        }

        $directory = trim($directory, '/');

        if (! str_starts_with($relativePath, $directory . '/')) {
            return false;
        }

        $absolute = public_path($relativePath);

        // Belt and braces: the path must still resolve inside public/uploads.
        $uploadsRoot = public_path('uploads');
        $real = realpath($absolute);

        if ($real === false || ! str_starts_with($real, realpath($uploadsRoot) ?: $uploadsRoot)) {
            return false;
        }

        return File::exists($real) && File::delete($real);
    }
}