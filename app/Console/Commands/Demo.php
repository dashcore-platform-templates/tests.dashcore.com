<?php

namespace App\Console\Commands;

use App\Demo\Demo as DemoData;
use Illuminate\Console\Command;

/**
 * Fills every page with made-up data that shows how it works, on a machine
 * where nothing has been scanned or run yet — or clears it again. Local
 * only: it refuses in production.
 */
class Demo extends Command
{
    protected $signature = 'tests:demo {--wipe : Remove the demo data instead (the starter workflows stay, switched off)}';

    protected $description = 'Fill every page with demo data, or clear it with --wipe';

    public function handle(DemoData $demo): int
    {
        if (app()->isProduction()) {
            $this->components->error('Demo data is for local use only; this install runs in production. Nothing was changed.');

            return self::FAILURE;
        }

        if ($this->option('wipe')) {
            $removed = $demo->wipe();

            foreach ($removed as $table => $count) {
                $this->components->twoColumnDetail($table, "{$count} removed");
            }

            $this->components->info('Demo data cleared.');

            return self::SUCCESS;
        }

        foreach ($demo->seed() as $page => $count) {
            $this->components->twoColumnDetail($page, (string) $count);
        }

        $this->components->info('Demo data is in. Every page it fills says so; clear it with php artisan tests:demo --wipe.');

        return self::SUCCESS;
    }
}
