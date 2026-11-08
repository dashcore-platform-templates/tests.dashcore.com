<?php

namespace App\Console\Commands;

use App\Scanning\ScanReaper;
use Illuminate\Console\Command;

class ReapScans extends Command
{
    protected $signature = 'tests:reap-scans';

    protected $description = 'Fail scans whose worker died, delete their jobs and queue each app once more';

    public function handle(ScanReaper $reaper): int
    {
        $reaped = $reaper->reap();

        if ($reaped === []) {
            $this->components->info('No dead scans.');

            return self::SUCCESS;
        }

        foreach ($reaped as $entry) {
            $scan = $entry['scan'];
            $this->components->twoColumnDetail(
                "Scan #{$scan->id} ({$scan->fleetApp?->key})",
                $entry['requeued'] !== null ? "<fg=yellow>failed</>, queued again as #{$entry['requeued']->id}" : '<fg=yellow>failed</>',
            );
            $this->line('  '.$entry['why']);
        }

        return self::SUCCESS;
    }
}
