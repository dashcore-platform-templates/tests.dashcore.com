<?php

namespace Dashcore\Testkit\Console;

use Illuminate\Console\Command;

/**
 * Adds the fleet baseline test to the app — Pest or PHPUnit, whichever the
 * suite uses — publishes the config, and records today's exceptions.
 */
class InstallCommand extends Command
{
    protected $signature = 'testkit:install {--force : Overwrite an existing baseline test and config}';

    protected $description = 'Add the DashCore fleet baseline test and record the app\'s current exceptions';

    public function handle(): int
    {
        $pest = is_file(base_path('vendor/bin/pest'));
        $target = base_path('tests/Feature/FleetBaselineTest.php');
        $config = config_path('testkit.php');

        foreach ([$target, $config] as $file) {
            if (is_file($file) && ! $this->option('force')) {
                $this->components->error(str_replace(base_path().'/', '', $file).' already exists. Use --force to replace it.');

                return self::FAILURE;
            }
        }

        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }

        copy(__DIR__.'/../../stubs/'.($pest ? 'FleetBaselineTest.pest.stub' : 'FleetBaselineTest.phpunit.stub'), $target);
        copy(__DIR__.'/../../config/testkit.php', $config);

        $this->components->info('Added tests/Feature/FleetBaselineTest.php ('.($pest ? 'Pest' : 'PHPUnit').') and config/testkit.php.');

        // A fresh process, so the published config is the one that is read.
        $this->call('config:clear');

        return $this->call('testkit:baseline');
    }
}
