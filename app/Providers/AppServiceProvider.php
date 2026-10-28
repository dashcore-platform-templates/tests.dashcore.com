<?php

namespace App\Providers;

use App\Services\FleetExport;
use App\Services\NavigationRegistry;
use App\Services\PermissionService;
use App\Simulation\OwnFleet;
use App\Simulation\Simulation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NavigationRegistry::class);
        $this->app->singleton(PermissionService::class);
        $this->app->singleton(Simulation::class);
        $this->app->singleton(FleetExport::class);
    }

    public function boot(): void
    {
        // Every fleet's tests walks only its own fleet's stand-ins.
        OwnFleet::apply();

        $this->registerNavigation();
    }

    private function registerNavigation(): void
    {
        $registry = app(NavigationRegistry::class);

        $registry->register('Tests', 'Fleet coverage', 'fleet.index', 'beaker', 1, 'fleet-tests');
        $registry->register('Tests', 'Test library', 'library.index', 'book-open', 2, 'fleet-tests');
        $registry->register('Tests', 'Proving Ground', 'simulation.index', 'play-circle', 3, 'fleet-tests');
        $registry->register('Tests', 'Client journey', 'simulation.journey-map', 'map', 4, 'fleet-tests');
        $registry->register('Tests', 'Workflows', 'workflows.index', 'cursor-arrow-rays', 5, 'workflows');
        $registry->register('Tests', 'Workflow health', 'workflows.health', 'heart', 6, 'workflows');
        $registry->register('Settings', 'Team', 'team', 'users', 1, 'team-management');
        $registry->register('Settings', 'Permissions', 'permissions', 'shield-check', 2, 'permissions');
    }
}
