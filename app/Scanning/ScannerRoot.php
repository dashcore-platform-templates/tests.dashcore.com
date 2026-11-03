<?php

namespace App\Scanning;

use App\Services\Install;

/**
 * Where the fleet's checkouts live when SCANNER_ROOT says nothing. On the
 * home install tests' own checkout is one of them (~/Herd/tests.dashcore.com),
 * so its parent folder is the root; on any other install there is no safe
 * guess, and nothing is scannable until the root is set.
 */
class ScannerRoot
{
    public static function apply(): void
    {
        $root = config('scanner.root');

        if (is_string($root) && trim($root) !== '') {
            return;
        }

        config(['scanner.root' => self::fallback()]);
    }

    public static function fallback(): ?string
    {
        if (! config('scanner.root_fallback') || ! Install::isHome()) {
            return null;
        }

        $parent = dirname(base_path());

        return is_dir($parent) ? $parent : null;
    }
}
