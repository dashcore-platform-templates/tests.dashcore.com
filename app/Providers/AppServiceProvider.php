<?php

namespace App\Providers;

use App\Services\NavigationRegistry;
use App\Services\PermissionService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NavigationRegistry::class);
        $this->app->singleton(PermissionService::class);
    }

    public function boot(): void
    {
        $this->registerNavigation();
    }

    private function registerNavigation(): void
    {
        $registry = app(NavigationRegistry::class);

        $registry->register('Tests', 'Fleet coverage', 'fleet.index', 'beaker', 1, 'fleet-tests');
        $registry->register('Settings', 'Team', 'team', 'users', 1, 'team-management');
        $registry->register('Settings', 'Permissions', 'permissions', 'shield-check', 2, 'permissions');
    }
}
