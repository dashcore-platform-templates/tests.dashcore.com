<?php

namespace Dashcore\DesignKit;

/**
 * A built-in style as this package ships it: styles/{name}.json and the
 * dist/{name}.css built from it, both written by design.dashcore.com.
 */
final class Style
{
    public static function exists(string $name): bool
    {
        return is_file(self::jsonPath($name)) && is_file(self::cssPath($name));
    }

    /** @return array<string,mixed>|null */
    public static function tokens(string $name): ?array
    {
        if (! is_file(self::jsonPath($name))) {
            return null;
        }

        $data = json_decode((string) file_get_contents(self::jsonPath($name)), true);

        return is_array($data) && is_array($data['tokens'] ?? null) ? $data['tokens'] : null;
    }

    /** The motif the style takes, which the components read from config('brand.motif'). */
    public static function motif(string $name): string
    {
        $data = is_file(self::jsonPath($name)) ? json_decode((string) file_get_contents(self::jsonPath($name)), true) : null;

        return is_array($data) && is_string($data['motif'] ?? null) ? $data['motif'] : 'accent-bar';
    }

    /** The standard as this release of the package carries it. */
    public static function standardPath(): string
    {
        return dirname(__DIR__).'/STANDARD.md';
    }

    public static function jsonPath(string $name): string
    {
        return dirname(__DIR__).'/styles/'.basename($name).'.json';
    }

    public static function cssPath(string $name): string
    {
        return dirname(__DIR__).'/dist/'.basename($name).'.css';
    }
}
