<?php

declare(strict_types=1);

namespace Dashcore\Bridge\Uploads;

use Symfony\Component\Mime\MimeTypes;

/**
 * Whether a type read from a file's first bytes fits the extension it was
 * stored under. Used on directly uploaded files, where the app never held
 * the whole file to detect it from.
 */
class FileTypes
{
    public static function matches(string $detected, string $extension): bool
    {
        $extension = strtolower($extension);

        if ($detected !== '' && in_array($extension, MimeTypes::getDefault()->getExtensions($detected), true)) {
            return true;
        }

        // Office files are containers; their first bytes read as the
        // container, not the document inside it.
        return match ($extension) {
            'docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp' => in_array($detected, ['application/zip', 'application/octet-stream'], true)
                || str_starts_with($detected, 'application/vnd.openxmlformats-officedocument.')
                || str_starts_with($detected, 'application/vnd.oasis.opendocument.'),
            'doc', 'xls', 'ppt' => in_array($detected, ['application/CDFV2', 'application/x-ole-storage', 'application/vnd.ms-office', 'application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-powerpoint', 'application/octet-stream'], true),
            'rtf' => in_array($detected, ['text/rtf', 'application/rtf'], true),
            // A recording's container is often read as Matroska, which WebM is.
            'webm' => $detected === 'video/x-matroska',
            default => false,
        };
    }
}
