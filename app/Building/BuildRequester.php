<?php

namespace App\Building;

use App\Enums\BuildStatus;
use App\Jobs\RunBuild;
use App\Models\Build;
use App\Models\FleetApp;
use App\Models\Surface;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Queues a build for an app, one at a time per app: two builds of one app
 * would share its build database and could pick overlapping targets.
 */
class BuildRequester
{
    public function __construct(private readonly BuildPlanner $planner) {}

    public function request(FleetApp $app, ?User $user = null, ?Surface $lead = null, bool $queue = true): ?Build
    {
        if (! $app->isScannable()) {
            return null;
        }

        $build = DB::transaction(function () use ($app, $user, $lead) {
            FleetApp::query()->whereKey($app->id)->lockForUpdate()->first();

            $active = $app->builds()->get()->contains(fn (Build $b) => $b->status->isActive());

            if ($active) {
                return null;
            }

            $targets = $this->planner->plan($app, $lead);

            if ($targets->isEmpty()) {
                return null;
            }

            return $app->builds()->create([
                'status' => BuildStatus::Queued,
                'scan_id' => $app->latestCompletedScan()->first()?->id,
                'requested_by' => $user?->id,
                'targets' => $targets->pluck('id')->all(),
            ]);
        });

        if ($build !== null && $queue) {
            RunBuild::dispatch($build);
        }

        return $build;
    }
}
