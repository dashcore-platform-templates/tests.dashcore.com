<?php

namespace App\Simulation;

use App\Services\FleetExport;
use App\Services\Install;

/**
 * Every fleet's tests walks only its own fleet's stand-ins. A Proving Ground
 * target belongs to its `fleet` (default the home fleet: the fake company is
 * Dashcore's), a stand-in fleet to the fleet it `stands_in_for`; whatever
 * belongs to another fleet is dropped from this install's config at boot,
 * and StandInGuard refuses a checkout fleets lists under another fleet.
 */
class OwnFleet
{
    /** Keep only this install's own targets and stand-in fleets in config. */
    public static function apply(): void
    {
        $own = fn (array $entry, string $key) => ($entry[$key] ?? config('install.home_fleet')) === Install::fleet();

        config([
            'simulation.targets' => array_filter((array) config('simulation.targets', []), fn ($target) => is_array($target) && $own($target, 'fleet')),
            'simulation.fleets' => array_filter((array) config('simulation.fleets', []), fn ($fleet) => is_array($fleet) && $own($fleet, 'stands_in_for')),
        ]);
    }

    /**
     * The fleets (fleets.dashcore.com slugs) whose checkouts this install may
     * drive: its own, and the stand-in fleets built for it.
     *
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_values(array_unique(array_filter([
            Install::fleet(),
            ...array_map(fn ($fleet) => is_array($fleet) ? ($fleet['install'] ?? null) : null, (array) config('simulation.fleets', [])),
        ], 'is_string')));
    }

    /**
     * Why a checkout belongs to another fleet, or null: fleets lists every
     * fleet's apps by the folder their checkout goes by.
     */
    public static function refusal(string $checkout, FleetExport $export): ?string
    {
        $folder = basename(rtrim($checkout, '/'));
        $owners = [];

        foreach ($export->fleets() as $slug => $fleet) {
            foreach ((array) ($fleet['apps'] ?? []) as $app) {
                if (is_array($app) && ($app['local'] ?? null) === $folder) {
                    $owners[] = (string) $slug;
                }
            }
        }

        if ($owners === [] || array_intersect($owners, self::slugs()) !== []) {
            return null;
        }

        return "{$folder} is a stand-in of the ".implode(', ', array_unique($owners)).' fleet, not of '.Install::fleet().'\'s: each tests walks only its own fleet\'s stand-ins.';
    }
}
