<?php

namespace App\Scanning;

/**
 * Finds an app's checkout under the configured root, by its production host.
 *
 * The resolved path must still sit inside the root after realpath(), so a
 * host containing `..` or a symlink pointing elsewhere is refused rather than
 * followed — the scanner runs code from whatever directory this returns.
 */
class CheckoutLocator
{
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
