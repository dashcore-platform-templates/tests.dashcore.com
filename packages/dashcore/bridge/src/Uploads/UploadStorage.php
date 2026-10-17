<?php

declare(strict_types=1);

namespace Dashcore\Bridge\Uploads;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The disk an app keeps uploaded files on, and the address it would give
 * one of them.
 *
 * Arriving whole is half of an upload's promise; the other half is that a
 * stranger cannot read it. A bucket made public, or a file stored on the
 * `public` disk, hands every upload to anyone with its URL. Standards
 * tests that the way a stranger would: the app stores a probe file as it
 * stores uploads, says where its URL points, and Standards fetches that
 * URL without signing anything.
 *
 * The disk is `bridge.uploads.disk`, or the direct-upload disk, or the
 * app's default disk, in that order.
 */
class UploadStorage
{
    public static function disk(): string
    {
        foreach ([config('bridge.uploads.disk'), DirectUpload::disk(), config('filesystems.default')] as $disk) {
            if (is_string($disk) && $disk !== '') {
                return $disk;
            }
        }

        return 'local';
    }

    /**
     * @return array{disk: string, driver: string}
     */
    public static function describe(): array
    {
        $disk = static::disk();

        return ['disk' => $disk, 'driver' => (string) config("filesystems.disks.{$disk}.driver")];
    }

    /**
     * The plain URL the disk gives a file, with no signature: what a link
     * to it would be. Null when the disk has no URL to give.
     */
    public static function url(string $path): ?string
    {
        try {
            $url = Storage::disk(static::disk())->url($path);
        } catch (Throwable) {
            return null;
        }

        return is_string($url) && $url !== '' ? $url : null;
    }
}
