<?php

namespace App\Simulation;

use App\Services\FleetExport;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Which practice fleet this install's customer journeys walk, read from
 * fleets' roster (`fleet:export --json`) — never built from the Mac's user,
 * which once pointed every journey at a fleet that did not exist
 * (practice-travischapman, REQ-683).
 *
 * The stand-in fleet's config (`from_roster`) carries `{install}` and
 * `{domain}` tokens; they are filled in from the one practice fleet whose
 * slug starts `practice-` (fleet:practice's naming), its domain from its
 * roster entry. SIMULATION_PRACTICE_INSTALL picks the fleet instead (its
 * domain still from the roster, else `practice-<x>` → `<x>.practice`), and
 * SIMULATION_PRACTICE_DOMAIN sets the domain. None or several candidates
 * leaves the fleet `unresolved`, naming them, and every journey refuses.
 *
 * Boot only reads what is cached (no process runs at boot: Console starts
 * `tests:host-runtime` with a 60-second limit); the commands that walk
 * call ensure(), and the schedule refreshes it hourly.
 */
class PracticeFleet
{
    public const CACHE_KEY = 'simulation.practice-roster';

    public const PREFIX = 'practice-';

    /** Fill in what the cached roster can; never runs a process. */
    public static function apply(): void
    {
        try {
            $roster = Cache::get(self::CACHE_KEY);
        } catch (Throwable) {
            $roster = null;
        }

        self::applyWith(is_array($roster) ? $roster : null, read: is_array($roster));
    }

    /** Read the roster when nothing is cached, then fill in. */
    public static function ensure(): void
    {
        try {
            $cached = Cache::get(self::CACHE_KEY);
        } catch (Throwable) {
            $cached = null;
        }

        is_array($cached) ? self::applyWith($cached, read: true) : self::refresh();
    }

    /** Read fleets' roster now, keep it for an hour (five minutes when unreadable), and fill in. */
    public static function refresh(): void
    {
        $roster = self::read(app(FleetExport::class));

        try {
            Cache::put(self::CACHE_KEY, $roster ?? false, $roster === null ? now()->addMinutes(5) : now()->addHour());
        } catch (Throwable) {
            // Uncached is only slower.
        }

        self::applyWith($roster, read: $roster !== null);
    }

    /**
     * The practice fleets fleets lists, as slug => domain, or null when
     * fleets cannot be read.
     *
     * @return array<string, ?string>|null
     */
    public static function read(FleetExport $export): ?array
    {
        $fleets = $export->fleets();

        if ($fleets === []) {
            return null;
        }

        $practice = [];

        foreach ($fleets as $slug => $fleet) {
            if (($fleet['practice'] ?? false) === true) {
                $domain = $fleet['domain'] ?? null;
                $practice[(string) $slug] = is_string($domain) && $domain !== '' ? $domain : null;
            }
        }

        return $practice;
    }

    /**
     * @param  array<string, ?string>|null  $roster  slug => domain
     */
    public static function applyWith(?array $roster, bool $read): void
    {
        foreach ((array) config('simulation.fleets', []) as $key => $fleet) {
            if (! is_array($fleet) || ! ($fleet['from_roster'] ?? false)) {
                continue;
            }

            $install = $fleet['install'] ?? null;
            $domain = $fleet['domain'] ?? null;
            $needsInstall = ! is_string($install) || $install === '' || $install === '{install}';
            $needsDomain = ! is_string($domain) || $domain === '' || $domain === '{domain}';

            if (! $needsInstall && ! $needsDomain && ! self::hasTokens($fleet)) {
                continue;
            }

            $why = null;

            if ($needsInstall) {
                $candidates = array_values(array_filter(array_keys($roster ?? []), fn (string $slug) => str_starts_with($slug, self::PREFIX)));

                if (! $read) {
                    $why = 'fleets\' roster has not been read yet (fleet:export --json in '.config('install.fleets_checkout').'), so the practice fleet is not known; set SIMULATION_PRACTICE_INSTALL to name it.';
                } elseif (count($candidates) !== 1) {
                    $why = ($candidates === []
                        ? 'fleets\' roster lists no practice fleet named '.self::PREFIX.'*'
                        : 'fleets\' roster lists several practice fleets ('.implode(', ', $candidates).')')
                        .'; set SIMULATION_PRACTICE_INSTALL to the one this install walks.';
                } else {
                    $install = $candidates[0];
                }
            }

            if ($why === null && $needsDomain) {
                $domain = $roster[$install] ?? null;

                if (! is_string($domain) || $domain === '') {
                    $domain = str_starts_with((string) $install, self::PREFIX)
                        ? substr((string) $install, strlen(self::PREFIX)).'.practice'
                        : null;
                }

                if ($domain === null) {
                    $why = "fleets' roster gives no domain for {$install}; set SIMULATION_PRACTICE_DOMAIN.";
                }
            }

            if ($why !== null) {
                config([
                    "simulation.fleets.{$key}.install" => null,
                    "simulation.fleets.{$key}.unresolved" => "The practice fleet is not known: {$why}",
                ]);

                continue;
            }

            $filled = self::fill($fleet, ['{install}' => (string) $install, '{domain}' => (string) $domain]);
            $filled['install'] = (string) $install;
            $filled['domain'] = (string) $domain;
            $filled['unresolved'] = null;

            config(["simulation.fleets.{$key}" => $filled]);
        }
    }

    /** Why a stand-in fleet cannot be walked, or null. */
    public static function refusal(array $fleet): ?string
    {
        $why = $fleet['unresolved'] ?? null;

        return is_string($why) && $why !== '' ? $why : null;
    }

    /**
     * @param  array<mixed>  $value
     * @param  array<string, string>  $tokens
     * @return array<mixed>
     */
    private static function fill(array $value, array $tokens): array
    {
        foreach ($value as $k => $v) {
            if (is_string($v)) {
                $value[$k] = strtr($v, $tokens);
            } elseif (is_array($v)) {
                $value[$k] = self::fill($v, $tokens);
            }
        }

        return $value;
    }

    /** @param  array<mixed>  $value */
    private static function hasTokens(array $value): bool
    {
        foreach ($value as $v) {
            if ((is_string($v) && (str_contains($v, '{install}') || str_contains($v, '{domain}'))) || (is_array($v) && self::hasTokens($v))) {
                return true;
            }
        }

        return false;
    }
}
