<?php

declare(strict_types=1);

namespace Dashcore\Bridge\Uploads;

/**
 * The largest file this app says it accepts, and the PHP limits that
 * decide whether such a file can actually arrive.
 *
 * The declaration is the contract: Standards sends a real file of that
 * size through every layer in front of the app and fails the app when one
 * of them refuses it. Without one there is nothing to hold the stack to —
 * talent's CV upload passed every test and was refused by the web server
 * in front of it, because nothing anywhere said how large a CV could be.
 */
class UploadLimits
{
    /**
     * Bytes the app declares, 0 when it takes no uploads at all, or null
     * when it has not said.
     */
    public static function declared(): ?int
    {
        $value = config('bridge.uploads.max');

        if ($value === null || $value === '') {
            return null;
        }

        if (in_array(strtolower(trim((string) $value)), ['none', 'off', 'false', '0'], true)) {
            return 0;
        }

        $bytes = static::toBytes((string) $value);

        return $bytes > 0 ? $bytes : null;
    }

    /**
     * What PHP itself will let through, in bytes. post_max_size of 0 means
     * no limit, as PHP reads it.
     *
     * @return array{file_uploads: bool, upload_max_filesize: int, post_max_size: int, max_file_uploads: int}
     */
    public static function php(): array
    {
        return [
            'file_uploads' => filter_var(ini_get('file_uploads'), FILTER_VALIDATE_BOOLEAN),
            'upload_max_filesize' => static::toBytes((string) ini_get('upload_max_filesize')),
            'post_max_size' => static::toBytes((string) ini_get('post_max_size')),
            'max_file_uploads' => (int) ini_get('max_file_uploads'),
        ];
    }

    /**
     * PHP's shorthand (20M, 512K, 1G) or a plain byte count, as bytes.
     * Anything unreadable is 0, never a guess.
     */
    public static function toBytes(string $value): int
    {
        if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*([kmg])?b?\s*$/i', $value, $m) !== 1) {
            return 0;
        }

        $multiplier = match (strtolower($m[2] ?? '')) {
            'k' => 1024,
            'm' => 1024 ** 2,
            'g' => 1024 ** 3,
            default => 1,
        };

        return (int) round((float) $m[1] * $multiplier);
    }
}
