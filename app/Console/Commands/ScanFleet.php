<?php

namespace App\Console\Commands;

use App\Models\FleetApp;
use App\Scanning\Scanner;
use App\Scanning\ScanRequester;
use Illuminate\Console\Command;

class ScanFleet extends Command
{
    protected $signature = 'tests:scan
                            {app?* : Fleet app keys to scan (default: every scannable app)}
                            {--now : Run in this process instead of queueing — for debugging one app}';

    protected $description = 'Scan fleet apps: run each suite under the probe and record which surfaces are tested';

    public function handle(ScanRequester $requester, Scanner $scanner): int
    {
        $keys = (array) $this->argument('app');

        $apps = FleetApp::query()
            ->when($keys !== [], fn ($q) => $q->whereIn('key', $keys))
            ->orderBy('key')
            ->get();

        if ($keys !== [] && $apps->count() !== count($keys)) {
            $this->components->error('Not in the fleet: '.implode(', ', array_diff($keys, $apps->pluck('key')->all())));

            return self::FAILURE;
        }

        foreach ($apps as $app) {
            if (! $app->isScannable()) {
                $this->components->twoColumnDetail($app->key, '<fg=gray>no local checkout</>');

                continue;
            }

            $scan = $requester->request($app, queue: ! $this->option('now'));

            if ($scan === null) {
                $this->components->twoColumnDetail($app->key, '<fg=yellow>already scanning</>');

                continue;
            }

            if (! $this->option('now')) {
                $this->components->twoColumnDetail($app->key, '<fg=green>queued</>');

                continue;
            }

            $scan = $scanner->run($scan);
            $this->components->twoColumnDetail(
                $app->key,
                $scan->error === null
                    ? "<fg=green>{$scan->score}%</> {$scan->surfaces_tested}/{$scan->surfaces_total} surfaces, {$scan->tests_failed} failing tests"
                    : '<fg=red>failed</> '.$scan->error,
            );
        }

        return self::SUCCESS;
    }
}
