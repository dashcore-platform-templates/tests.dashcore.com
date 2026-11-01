<?php

namespace App\Scanning;

use App\Services\FleetExport;
use App\Services\Install;

/**
 * Finds an app's checkout under the configured root, by its production host
 * — or, on another fleet's install, by the local folder fleets keeps it in.
 *
 * The resolved path must still sit inside the root after realpath(), so a
 * host containing `..` or a symlink pointing elsewhere is refused rather than
 * followed — the scanner runs code from whatever directory this returns.
 */
class CheckoutLocator
{
    public function __construct(private readonly FleetExport $fleets) {}

    public function root(): ?string
    {
        $root = config('scanner.root');

        if (! is_string($root) || $root === '') {
            return null;
        }

        $real = realpath($root);

        return $real === false ? null : $real;
    }

    public function find(string $host): ?string
    {
        $root = $this->root();

        if ($root === null || $host === '' || str_contains($host, '/') || str_contains($host, '..')) {
            return null;
        }

        $path = realpath($root.DIRECTORY_SEPARATOR.$host);

        if ($path === false || ! is_dir($path) || ! $this->inside($path, $root)) {
            return null;
        }

        return is_file($path.'/artisan') ? $path : null;
    }

    /**
     * $key's checkout: under its host first; failing that, on any install
     * but the home one, under the local folder fleets' export names for it
     * in this install's fleet. A practice fleet's roster hosts are tailnet
     * names (api.practice-req145.practice.dashcore.com) while its checkouts
     * are named after the fleet's local domain (api.req145.practice). The
     * folder gets the same checks as a host: a bare name inside the root
     * holding artisan, or nothing.
     */
    public function forApp(string $key, string $host): ?string
    {
        $found = $this->find($host);

        if ($found !== null || Install::isHome() || $key === '') {
            return $found;
        }

        $local = $this->fleets->live(Install::fleet(), $key)['local'] ?? null;

        return is_string($local) && $local !== '' ? $this->find($local) : null;
    }

    public function contains(string $path): bool
    {
        $root = $this->root();
        $real = realpath($path);

        return $root !== null && $real !== false && $this->inside($real, $root);
    }

    private function inside(string $path, string $root): bool
    {
        return str_starts_with($path.DIRECTORY_SEPARATOR, $root.DIRECTORY_SEPARATOR) && $path !== $root;
    }
}
