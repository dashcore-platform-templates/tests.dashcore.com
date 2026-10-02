<?php

namespace App\Console\Commands;

use App\Building\BuildRequester;
use App\Building\TestkitInstaller;
use App\Models\FleetApp;
use Illuminate\Console\Command;

class InstallTestkit extends Command
{
    protected $signature = 'tests:install-testkit
                            {app?* : Fleet app keys (default: every scannable app)}
                            {--now : Run in this process instead of queueing}';

    protected $description = 'Install or upgrade the fleet baseline (dashcore/testkit) in fleet apps, one verified PR each';

    public function handle(BuildRequester $requester, TestkitInstaller $installer): int
    {
        $keys = (array) $this->argument('app');
        $apps = FleetApp::query()->when($keys !== [], fn ($q) => $q->whereIn('key', $keys))->orderBy('key')->get();

        if ($keys !== [] && $apps->count() !== count($keys)) {
            $this->components->error('Not in the fleet: '.implode(', ', array_diff($keys, $apps->pluck('key')->all())));

            return self::FAILURE;
        }

        foreach ($apps as $app) {
            $build = $requester->requestTestkit($app, queue: ! $this->option('now'));

            if ($build === null) {
                $this->components->twoColumnDetail($app->key, $app->isScannable() ? '<fg=gray>a build is running or a baseline PR is open</>' : '<fg=gray>no local checkout</>');

                continue;
            }

            if (! $this->option('now')) {
                $this->components->twoColumnDetail($app->key, '<fg=green>queued</>');

                continue;
            }

            $build = $installer->run($build);
            $this->components->twoColumnDetail($app->key, match ($build->status->value) {
                'opened' => "<fg=green>PR opened</> {$build->pr_url}",
                'ready' => "<fg=green>ready</> on branch {$build->branch}",
                'rejected' => '<fg=yellow>not proposed</> '.strtok((string) $build->error, "\n"),
                default => '<fg=red>'.$build->status->value.'</> '.$build->error,
            });
        }

        return self::SUCCESS;
    }
}
