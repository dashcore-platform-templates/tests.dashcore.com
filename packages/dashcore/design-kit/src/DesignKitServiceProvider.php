<?php

namespace Dashcore\DesignKit;

use Dashcore\DesignKit\Commands\ApplyCommand;
use Dashcore\DesignKit\Commands\AuditCommand;
use Dashcore\DesignKit\Commands\CheckCommand;
use Dashcore\DesignKit\Commands\ConnectCommand;
use Dashcore\DesignKit\Commands\UpdateCommand;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

/**
 * Installs into a Laravel Livewire Flux kit. Registers the three commands, the
 * brand config the sign-in page reads, the source design:apply pulls from, the
 * five custom components from standard/06 under <x-ds::…>, and the two-panel
 * sign-in layout as <x-design-kit::layouts.auth>.
 */
class DesignKitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/brand.php', 'brand');
        $this->mergeConfigFrom(__DIR__.'/../config/design-kit.php', 'design-kit');

        // An app on a style takes the motif and title face from the style, not
        // from DESIGN_* in its environment. design:apply writes those keys to
        // .env.example only, so an environment without them fell back to the
        // config defaults and drew the page title in the wrong face.
        $style = Style::named($this->app->basePath('design/design.json'));
        $brand = $style !== null ? Style::brand($style) : null;
        if ($brand !== null) {
            config(['brand.motif' => $brand['motif'], 'brand.second_kind' => $brand['second_kind'], 'brand.condensed' => $brand['condensed']]);
        }

        // dashcore/ui's classes, for one major, so an app's own code and tests
        // that name them carry on once it is removed. Only when it is gone:
        // while both are installed, its classes are the real ones.
        foreach ([
            'Dashcore\\Ui\\Menu' => Frame\Menu::class,
            'Dashcore\\Ui\\Version' => Frame\Version::class,
            'Dashcore\\Ui\\Testing\\Shell' => Testing\Shell::class,
        ] as $old => $new) {
            if (! class_exists($old)) {
                class_alias($new, $old);
            }
        }
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'design-kit');
        Blade::anonymousComponentPath(__DIR__.'/../resources/views/ds', 'ds');

        // <x-dashcore::shell> and the rest of dashcore/ui's names, for one
        // major. Not while dashcore/ui is installed, which registers them
        // itself; two packages answering one prefix would be a coin toss.
        if (! class_exists('Dashcore\\Ui\\UiServiceProvider', false) && ! $this->app->providerIsLoaded('Dashcore\\Ui\\UiServiceProvider')) {
            Blade::anonymousComponentPath(__DIR__.'/../resources/views/ds', 'dashcore');
        }

        $this->publishes([
            __DIR__.'/../config/brand.php' => config_path('brand.php'),
            __DIR__.'/../config/design-kit.php' => config_path('design-kit.php'),
        ], 'design-kit-config');
        $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/design-kit')], 'design-kit-views');

        if ($this->app->runningInConsole()) {
            $this->commands([ApplyCommand::class, AuditCommand::class, CheckCommand::class, ConnectCommand::class, UpdateCommand::class]);
        }
    }
}
