<?php

namespace App\Console\Commands;

use App\Library\Library;
use App\Models\FleetApp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Fills the test library from each app's latest completed scan — the commit
 * it ran and the outcomes it saw — without running anything. Scans keep it
 * current from then on; this is for catching up.
 */
class SyncLibrary extends Command
{
    protected $signature = 'tests:library {keys?* : App keys; all apps when omitted}';

    protected $description = 'Bring the test library up to date from the latest scans';

    public function handle(Library $library): int
    {
        $apps = FleetApp::query()
            ->when($this->argument('keys'), fn ($q, array $keys) => $q->whereIn('key', $keys))
            ->orderBy('key')
            ->get();

        foreach ($apps as $app) {
            if (! $app->local_path || ! is_dir($app->local_path)) {
                $this->components->twoColumnDetail($app->key, '<fg=yellow>no checkout</>');

                continue;
            }

            try {
                $scan = $app->latestCompletedScan;
                $result = $scan?->commit_sha
                    ? $library->captureScan($scan)
                    : $library->capture($app, $app->local_path, trim(Process::path($app->local_path)->run(['git', 'rev-parse', 'HEAD'])->output()));

                $this->components->twoColumnDetail($app->key, "{$result['files']} files ({$result['changed']} changed) · {$result['tests']} tests".($scan ? '' : ' · no scan yet'));
            } catch (Throwable $e) {
                $this->components->twoColumnDetail($app->key, '<fg=red>'.$e->getMessage().'</>');
            }
        }

        return self::SUCCESS;
    }
}
