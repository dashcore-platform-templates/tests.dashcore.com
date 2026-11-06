<?php

namespace App\Scanning;

/**
 * Where the fleet's checkouts live when SCANNER_ROOT says nothing. Every
 * install's own checkout sits beside the apps it scans (~/Herd/tests.<fleet>
 * beside ~/Herd/<app>), so its parent folder is the root. CheckoutLocator and
 * StandInGuard still refuse anything that does not belong.
 *
 * The Proving Ground targets' checkouts follow the resolved root: a target
 * with no checkout of its own is its `checkout_folder` under it.
 */
class ScannerRoot
{
    public static function apply(): void
    {
        $root = config('scanner.root');

        if (! is_string($root) || trim($root) === '') {
            $root = self::fallback();
            config(['scanner.root' => $root]);
        }

        if (! is_string($root) || trim($root) === '') {
            return;
        }

        foreach ((array) config('simulation.targets', []) as $key => $target) {
            $folder = $target['checkout_folder'] ?? null;

            if (empty($target['checkout']) && is_string($folder) && $folder !== '') {
                config(["simulation.targets.{$key}.checkout" => rtrim($root, '/').'/'.$folder]);
            }
        }
    }

    public static function fallback(): ?string
    {
        if (! config('scanner.root_fallback')) {
            return null;
        }

        $parent = dirname(base_path());

        return is_dir($parent) ? $parent : null;
    }
}
