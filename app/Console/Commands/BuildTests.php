<?php

namespace App\Console\Commands;

use App\Building\Builder;
use App\Building\BuildRequester;
use App\Models\Build;
use App\Models\FleetApp;
use App\Models\Surface;
use App\Services\Install;
use Illuminate\Console\Command;

class BuildTests extends Command
{
    protected $signature = 'tests:build
                            {app?* : Fleet app keys (default: every app with gaps and no build PR waiting on review)}
                            {--surface= : Lead with this surface id (one app only)}
                            {--now : Run in this process instead of queueing}';

    protected $description = 'Have an agent write tests for an app\'s open surfaces, verify them, and open a PR for review';

    public function handle(BuildRequester $requester, Builder $builder): int
    {
        if (! BuildRequester::allowed()) {
            $this->components->error('Only Dashcore\'s own tests builds tests and opens PRs; this install is the '.Install::fleet().' fleet\'s.');

            return self::FAILURE;
        }

        $keys = (array) $this->argument('app');
        $surface = $this->option('surface') ? Surface::find((int) $this->option('surface')) : null;

        if ($this->option('surface') && ($surface === null || count($keys) !== 1 || $surface->fleetApp->key !== $keys[0])) {
            $this->components->error('--surface needs exactly one app, and a surface of that app.');

            return self::FAILURE;
        }

        $apps = FleetApp::query()->when($keys !== [], fn ($q) => $q->whereIn('key', $keys))->orderBy('key')->get();

        if ($keys !== [] && $apps->count() !== count($keys)) {
            $this->components->error('Not in the fleet: '.implode(', ', array_diff($keys, $apps->pluck('key')->all())));

            return self::FAILURE;
        }

        foreach ($apps as $app) {
            if ($keys === [] && $app->builds()->holdingTargets()->exists()) {
                $this->components->twoColumnDetail($app->key, '<fg=gray>a build is running or waiting on review</>');

                continue;
            }

            $build = $requester->request($app, lead: $surface, queue: ! $this->option('now'));

            if ($build === null) {
                $this->components->twoColumnDetail($app->key, '<fg=gray>nothing to build</>');

                continue;
            }

            if (! $this->option('now')) {
                $this->components->twoColumnDetail($app->key, '<fg=green>queued</> '.count($build->targets).' target(s)');

                continue;
            }

            $build = $builder->run($build);
            $this->components->twoColumnDetail($app->key, $this->outcome($build));
        }

        return self::SUCCESS;
    }

    private function outcome(Build $build): string
    {
        return match ($build->status->value) {
            'opened' => "<fg=green>PR opened</> {$build->pr_url} ({$build->targets_proven} proven)",
            'ready' => "<fg=green>ready</> on branch {$build->branch} ({$build->targets_proven} proven)",
            'rejected' => '<fg=yellow>rejected</> '.strtok((string) $build->error, "\n"),
            default => '<fg=red>'.$build->status->value.'</> '.$build->error,
        };
    }
}
