<?php

namespace App\Scanning;

use App\Enums\ScanStatus;
use App\Jobs\RunScan;
use App\Models\FleetApp;
use App\Models\Scan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Queues a scan for an app, unless one is already queued or running — two
 * scans of one app would share its scratch database.
 */
class ScanRequester
{
    public function request(FleetApp $app, ?User $user = null, bool $queue = true): ?Scan
    {
        if (! $app->isScannable()) {
            return null;
        }

        $scan = DB::transaction(function () use ($app, $user) {
            FleetApp::query()->whereKey($app->id)->lockForUpdate()->first();

            if ($app->hasActiveScan()) {
                return null;
            }

            return $app->scans()->create([
                'status' => ScanStatus::Queued,
                'requested_by' => $user?->id,
            ]);
        });

        if ($scan !== null && $queue) {
            RunScan::dispatch($scan);
        }

        return $scan;
    }
}
