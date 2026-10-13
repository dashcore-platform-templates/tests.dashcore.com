<?php

namespace Dashcore\Bridge;

use Dashcore\Bridge\Client\BridgeManager;
use Dashcore\Bridge\Console\CheckContractsCommand;
use Dashcore\Bridge\Console\ConnectCommand;
use Dashcore\Bridge\Console\DemoRefreshCommand;
use Dashcore\Bridge\Console\DoctorCommand;
use Dashcore\Bridge\Console\InstallCommand;
use Dashcore\Bridge\Console\KeysGenerateCommand;
use Dashcore\Bridge\Console\KeysRotateCommand;
use Dashcore\Bridge\Console\ReportCallsCommand;
use Dashcore\Bridge\Console\ReportMailCommand;
use Dashcore\Bridge\Console\ReportTelemetryCommand;
use Dashcore\Bridge\Console\SsoStatusCommand;
use Dashcore\Bridge\Http\Middleware\EnsureBridgeScope;
use Dashcore\Bridge\Http\Middleware\SendSignInToSso;
use Dashcore\Bridge\Http\Middleware\SetSecurityHeaders;
use Dashcore\Bridge\Http\Middleware\VerifyBridgeRequest;
use Dashcore\Bridge\Contracts\ResolvesSsoUser;
use Dashcore\Bridge\Identity\IdentityResolver;
use Dashcore\Bridge\Keys\ConfigKeysetResolver;
use Dashcore\Bridge\Keys\KeysetResolver;
use Dashcore\Bridge\Keys\ManifestKeysetResolver;
use Dashcore\Bridge\Sso\DatabaseUserResolver;
use Dashcore\Bridge\Sso\PasswordSignIn;
use Dashcore\Bridge\Sso\Provider;
use Dashcore\Bridge\Sso\SsoFlow;
use Dashcore\Bridge\Mail\MailRecorder;
use Dashcore\Bridge\Telemetry\Config as TelemetryConfig;
use Dashcore\Bridge\Telemetry\Listeners as TelemetryListeners;
use Dashcore\Bridge\Telemetry\Recorder;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class BridgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bridge.php', 'bridge');

        $this->app->singleton(KeysetResolver::class, fn ($app) => match ($app['config']->get('bridge.driver', 'config')) {
            'control' => new ManifestKeysetResolver,
            default => new ConfigKeysetResolver,
        });
        $this->app->singleton(BridgeManager::class);
        $this->app->singleton(IdentityResolver::class);

        $this->app->singleton(Provider::class);
        $this->app->singleton(SsoFlow::class);

        // Bound, not registered as a concrete: an app with its own rules for
        // who may exist here binds its own over this one.
        $this->app->bind(ResolvesSsoUser::class, DatabaseUserResolver::class);

        $this->app->singleton(TelemetryConfig::class);
        $this->app->singleton(Recorder::class);
        $this->app->singleton(TelemetryListeners::class);
        $this->app->singleton(MailRecorder::class);
    }

    public function boot(): void
    {
        $router = $this->app['router'];
        $router->aliasMiddleware('bridge.auth', VerifyBridgeRequest::class);
        $router->aliasMiddleware('bridge.scope', EnsureBridgeScope::class);

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/bridge.php');

        // Attached unconditionally; the listeners themselves check whether
        // telemetry is on. Deciding here instead would read config during
        // provider boot, before an app has had a chance to set it — and would
        // make the switch a restart rather than a setting.
        $this->app->make(TelemetryListeners::class)->subscribe($this->app['events']);
        $this->app->make(MailRecorder::class)->subscribe($this->app['events']);

        // Scheduled here rather than in each app's console routes: a mail
        // ledger that covers only the apps that remembered a line is the
        // kind of gap it exists to close.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('bridge:report-mail --prune')
                ->everyTwoMinutes()
                ->withoutOverlapping()
                ->runInBackground();
        });

        // The same, for sso required: both check the mode when they run, and
        // do nothing unless it is `required`.
        $this->app->make(PasswordSignIn::class)->subscribe($this->app['events']);
        // Through the HTTP kernel, not the router: the kernel re-syncs its
        // own groups onto the router, which drops a group edit made there.
        $kernel = $this->app->make(HttpKernel::class);
        if ($kernel instanceof Kernel) {
            $kernel->appendMiddlewareToGroup('web', SendSignInToSso::class);

            // Global, not the web group: an API answer and an error page are
            // pages a browser reads too. The middleware checks the switch per
            // request, so turning it off is a setting rather than a restart.
            $kernel->pushMiddleware(SetSecurityHeaders::class);
        }

        $this->publishes([
            __DIR__.'/../config/bridge.php' => config_path('bridge.php'),
        ], 'bridge-config');

        // Not loaded with the package's own migrations: it alters the app's
        // users table, which is the app's to own. An app that wants the
        // column publishes it and reads the diff first.
        $this->publishes([
            __DIR__.'/../database/stubs/add_sso_subject_to_users_table.php.stub' => database_path(
                'migrations/'.date('Y_m_d_His').'_add_sso_subject_to_users_table.php'
            ),
        ], 'bridge-sso-migration');

        if ($this->app->runningInConsole()) {
            $this->commands([KeysGenerateCommand::class, KeysRotateCommand::class, InstallCommand::class, ConnectCommand::class, DoctorCommand::class, DemoRefreshCommand::class, ReportCallsCommand::class, CheckContractsCommand::class, ReportTelemetryCommand::class, ReportMailCommand::class, SsoStatusCommand::class]);
        }
    }
}
