<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Which fleet this tests site belongs to. Every fleet's tests runs on the
 * same Mac, so anything an install makes on the host is named after it —
 * except the home install's, which keeps the names it had before there was
 * more than one (tests_scan_{app}, com.dashcore.queue-tests).
 */
class Install
{
    public static function fleet(): string
    {
        return (string) config('install.fleet');
    }

    public static function host(): string
    {
        return (string) config('install.host');
    }

    public static function isHome(): bool
    {
        return self::fleet() === (string) config('install.home_fleet');
    }

    /** The fleet as a name part: lower case, letters, digits and underscores. */
    public static function key(): string
    {
        return Str::of(self::fleet())->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();
    }

    /**
     * $name, prefixed with this install's key unless it is the home install:
     * "sales" stays "sales" on tests.dashcore.com and is "acme__sales" on
     * acme's. The double underscore keeps the two apart from any app key.
     */
    public static function scoped(string $name): string
    {
        return self::isHome() ? $name : self::key().'__'.$name;
    }

    /**
     * A launchd label for one of this install's processes:
     * com.dashcore.queue-tests at home, com.dashcore.queue-tests.acme elsewhere.
     */
    public static function label(string $base, string $suffix = ''): string
    {
        return $base.(self::isHome() ? '' : '.'.str_replace('_', '-', self::key())).$suffix;
    }
}
