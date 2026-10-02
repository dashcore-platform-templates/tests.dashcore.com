<?php

namespace App\Console\Commands;

use App\Scanning\FleetSync;
use Illuminate\Console\Command;

class SyncFleet extends Command
{
    protected $signature = 'tests:sync-fleet';

    protected $description = 'Read this install\'s fleet from the control plane\'s signed manifest and reconcile the app list';

    public function handle(FleetSync $sync): int
    {
        $result = $sync->sync();

        if ($result['error'] !== null) {
            $this->components->error($result['error']);

            return self::FAILURE;
        }

        foreach (['added' => 'green', 'updated' => 'yellow', 'removed' => 'red'] as $change => $colour) {
            $this->components->twoColumnDetail($change, (string) count($result[$change]));

            foreach ($result[$change] as $key) {
                $this->components->twoColumnDetail('  '.$key, "<fg={$colour}>{$change}</>");
            }
        }

        return self::SUCCESS;
    }
}
