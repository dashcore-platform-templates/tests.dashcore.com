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

    /**
     * The style an app's design/design.json names, or null for an app on a
     * design of its own (or none).
     */
    public static function named(string $designFile): ?string
    {
        $data = is_file($designFile) ? json_decode((string) file_get_contents($designFile), true) : null;
        $name = is_array($data) ? ($data['style'] ?? null) : null;

        return is_string($name) && self::exists($name) ? $name : null;
    }

    /**
     * What the components read from config('brand') that the style decides:
     * the motif, the kind of second face, and whether it is condensed.
     *
     * @return array{motif: string, second_kind: string, condensed: bool}|null
     */
    public static function brand(string $name): ?array
    {
        $second = self::tokens($name)['fonts']['second'] ?? null;

        if (! is_array($second) || ! is_string($second['kind'] ?? null)) {
            return null;
        }

        return [
            'motif' => self::motif($name),
            'second_kind' => $second['kind'],
            'condensed' => (bool) ($second['condensed'] ?? false),
        ];
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
