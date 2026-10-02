<?php

namespace Dashcore\Testkit;

use Dashcore\Testkit\Console\BaselineCommand;
use Dashcore\Testkit\Console\InstallCommand;
use Illuminate\Support\ServiceProvider;

class TestkitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/testkit.php', 'testkit');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([__DIR__.'/../config/testkit.php' => config_path('testkit.php')], 'testkit-config');

        $this->commands([InstallCommand::class, BaselineCommand::class]);
    }
}
