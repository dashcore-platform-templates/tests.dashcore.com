<?php

declare(strict_types=1);

namespace Dashcore\Bridge\Uploads;

use finfo;
use Illuminate\Support\Facades\Storage;

/**
 * Uploads that go from the browser straight to storage, never through the
 * app.
 *
 * Every fleet app on Laravel Cloud sits behind Cloudflare, which refuses
 * any request body over 100 MB before the app sees it; no PHP or nginx
 * setting changes that. Object storage (R2) takes up to 5 GiB in a single
 * PUT. So the app signs a short-lived URL for one file, the browser PUTs
 * the file to it (resources/js/direct-upload.js), and the app checks what
 * landed before trusting it.
 *
 * Locally the same code works on a disk with `'serve' => true`: Laravel
 * signs a PUT to the app's own storage route instead.
 */
class DirectUpload
{
    /**
     * A signed PUT for one file, and the headers the browser must send with
     * it. The type is signed, so the browser has to send exactly that.
     *
     * @return array{url: string, headers: array<string, string>}
     */
    public static function target(string $disk, string $path, string $contentType, int $minutes = 30): array
    {
        ['url' => $url, 'headers' => $headers] = Storage::disk($disk)
            ->temporaryUploadUrl($path, now()->addMinutes($minutes), ['ContentType' => $contentType]);

        // The S3 signer lists Host, which a browser sets itself and refuses
        // to be told; everything else it signed must be sent as given.
        $flat = [];

        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) !== 'host') {
                $flat[(string) $name] = is_array($value) ? implode(', ', $value) : (string) $value;
            }
        }

        return ['url' => $url, 'headers' => ['Content-Type' => $contentType] + $flat];
    }

    /**
     * The first time the app sees a directly uploaded file. It must be no
     * larger than the rule allows (a signed PUT cannot cap the size) and,
     * by its first bytes, a type `$accepts` takes. One that fails is
     * deleted, not left for anyone to find.
     *
     * @param  callable(string $detected): bool  $accepts  given the MIME type read from the file's first bytes
     */
    public static function verify(string $disk, string $path, int $maxBytes, callable $accepts): bool
    {
        $storage = Storage::disk($disk);

        if (! $storage->exists($path)) {
            return false;
        }

        $size = $storage->size($path);

        if ($size > 0 && $size <= $maxBytes && $accepts(static::sniff($disk, $path))) {
            return true;
        }

        $storage->delete($path);

        return false;
    }

    /**
     * The MIME type of a stored file, read from its first 4 KB only, so a
     * large file is never downloaded to answer.
     */
    public static function sniff(string $disk, string $path): string
    {
        $stream = Storage::disk($disk)->readStream($path);

        if (! is_resource($stream)) {
            return '';
        }

        try {
            $head = (string) fread($stream, 4096);
        } finally {
            fclose($stream);
        }

        return (new finfo(FILEINFO_MIME_TYPE))->buffer($head) ?: '';
    }

    /**
     * The disk this app's files go to directly, as the upload contract
     * reports it; null when uploads come through the app.
     *
     * @return array{disk: string, driver: string, signs_uploads: bool}|null
     */
    public static function describe(): ?array
    {
        $disk = static::disk();

        if ($disk === null) {
            return null;
        }

        return [
            'disk' => $disk,
            'driver' => (string) config("filesystems.disks.{$disk}.driver"),
            'signs_uploads' => static::signs($disk),
        ];
    }

    public static function disk(): ?string
    {
        $disk = config('bridge.uploads.direct');

        return is_string($disk) && $disk !== '' ? $disk : null;
    }

    public static function signs(string $disk): bool
    {
        try {
            return Storage::disk($disk)->providesTemporaryUploadUrls();
        } catch (\Throwable) {
            return false;
        }
    }
}
