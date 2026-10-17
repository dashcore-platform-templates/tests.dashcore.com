<?php

namespace Dashcore\DesignKit;

use InvalidArgumentException;

/**
 * Where a design comes from. `design:apply` takes a slug, a URL or a local
 * file, and this class tells them apart and turns what was fetched into the
 * files that belong under design/. Pure: the command does the HTTP.
 */
final class Source
{
    /** The document design.dashcore.com serves at /designs/{slug}/pack.json. */
    public const MANIFEST_SCHEMA = 'dashcore-design-pack/1';

    /** Must match App\Design\Pack::VERSION_LINE in the tool. The stamp follows it. */
    public const VERSION_LINE = 'Standard version: ';

    /**
     * The stamp out of a STANDARD.md, or null when the file predates stamping.
     * Comparing two of these is how a kit finds out the standard moved under it.
     */
    public static function standardVersion(string $standard): ?string
    {
        $pattern = '/^'.preg_quote(self::VERSION_LINE, '/').'([0-9a-f]{6,40})\s*$/m';

        return preg_match($pattern, $standard, $m) === 1 ? $m[1] : null;
    }

    /**
     * The stamp a fetched pack is carrying, without unpacking it.
     *
     * The manifest states it outright. A pack made before it did still has the
     * stamp inside its STANDARD.md, so read that rather than reporting nothing
     * and leaving the app unable to tell whether it is behind.
     *
     * @param  array<string,mixed>  $document
     */
    public static function publishedStandardVersion(array $document): ?string
    {
        $stated = $document['standard_version'] ?? null;
        if (is_string($stated) && preg_match('/^[0-9a-f]{6,40}$/', $stated) === 1) {
            return $stated;
        }

        $files = $document['files'] ?? null;
        $standard = is_array($files) ? ($files['STANDARD.md'] ?? null) : null;

        return is_string($standard) ? self::standardVersion($standard) : null;
    }

    /**
     * The slug an applied design file names for itself, when it has one.
     *
     * Files written before this was added have no slug, and there is no way to
     * infer one: the only alternative was matching the accent and pairing
     * against every design in the tool, which stops working the moment two
     * designs share both. So this returns null and the caller asks.
     *
     * Validated on the way out, because it becomes part of a URL.
     *
     * @param  array<string,mixed>  $design
     */
    public static function designSlug(array $design): ?string
    {
        $slug = $design['slug'] ?? null;

        return is_string($slug) && preg_match('/^[a-z0-9-]+$/', $slug) === 1 ? $slug : null;
    }

    /** 'file', 'url' or 'slug'. */
    public static function kind(string $argument): string
    {
        if (preg_match('~^https?://~i', $argument)) {
            return 'url';
        }

        if (str_contains($argument, '/') || str_contains($argument, '\\') || str_ends_with($argument, '.json')) {
            return 'file';
        }

        return 'slug';
    }

    /** The manifest URL for a slug on a design.dashcore.com instance. */
    public static function url(string $slug, string $base): string
    {
        return rtrim($base, '/').'/designs/'.rawurlencode($slug).'/pack.json';
    }

    /**
     * The files a fetched document stands for, keyed by name under design/.
     * A pack manifest carries all four; a bare design file stands for
     * design.json alone. Names are files only, never paths, so a manifest can
     * write nothing outside design/.
     *
     * @param  array<string,mixed>  $document
     * @return array<string,string>
     */
    public static function files(array $document): array
    {
        if (($document['schema'] ?? null) === self::MANIFEST_SCHEMA && is_array($document['files'] ?? null)) {
            $files = [];
            foreach ($document['files'] as $name => $contents) {
                if (! is_string($name) || ! is_string($contents) || $name === '' || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, '..')) {
                    throw new InvalidArgumentException("The pack manifest names a file it should not: {$name}.");
                }
                $files[$name] = $contents;
            }

            if (! isset($files['design.json'])) {
                throw new InvalidArgumentException('The pack manifest has no design.json.');
            }

            return $files;
        }

        if (isset($document['tokens'])) {
            return ['design.json' => json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"];
        }

        throw new InvalidArgumentException('That is neither a design pack nor a design file. Export it from design.dashcore.com.');
    }
}
