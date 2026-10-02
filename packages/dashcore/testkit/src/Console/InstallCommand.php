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
        $pest = $this->pestBindsFeatureTests();
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

    /**
     * A Pest-style test only works where tests/Pest.php gives Feature tests a
     * TestCase to run in; otherwise it runs with no application at all. A
     * class-based test works under either runner, so it is the safe default.
     */
    private function pestBindsFeatureTests(): bool
    {
        $file = base_path('tests/Pest.php');

        if (! is_file(base_path('vendor/bin/pest')) || ! is_file($file)) {
            return false;
        }

        return (bool) preg_match('/->in\(\s*[\'"]Feature[\'"]/', (string) file_get_contents($file));
    }
}
