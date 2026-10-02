<?php

namespace App\Console\Commands;

use App\Models\FleetApp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Scans keep their summary for ever — that is the trend line — but the
 * per-test and per-surface detail is only worth keeping for recent scans.
 * A large suite writes thousands of rows a night.
 */
class PruneScans extends Command
{
    protected $signature = 'tests:prune {--keep=10 : Scans per app whose detail is kept}';

    protected $description = 'Drop per-test and per-surface detail from all but the most recent scans of each app';

    public function handle(): int
    {
        $keep = max(1, (int) $this->option('keep'));
        $pruned = 0;

        foreach (FleetApp::withTrashed()->pluck('id') as $appId) {
            $old = DB::table('scans')
                ->where('fleet_app_id', $appId)
                ->orderByDesc('id')
                ->offset($keep)
                ->limit(PHP_INT_MAX)
                ->pluck('id');

            if ($old->isEmpty()) {
                continue;
            }

            DB::transaction(function () use ($old, &$pruned) {
                $pruned += DB::table('scan_tests')->whereIn('scan_id', $old)->delete();
                $pruned += DB::table('scan_surfaces')->whereIn('scan_id', $old)->delete();
                DB::table('scans')->whereIn('id', $old)->update(['output' => null]);
            });
        }

        $this->components->info("Pruned {$pruned} detail row(s), keeping the latest {$keep} scans per app in full.");

        return self::SUCCESS;
    }
}
