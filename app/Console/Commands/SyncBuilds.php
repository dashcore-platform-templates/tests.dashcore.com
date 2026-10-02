<?php

namespace App\Console\Commands;

use App\Enums\BuildStatus;
use App\Models\Build;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Follows the PRs builds opened. A merged PR shows up as coverage on the next
 * scan; a closed one frees its targets for another build.
 */
class SyncBuilds extends Command
{
    protected $signature = 'tests:sync-builds';

    protected $description = 'Update builds whose PRs were merged or closed';

    public function handle(): int
    {
        Build::query()->where('status', BuildStatus::Opened)->whereNotNull('pr_url')->each(function (Build $build) {
            $view = Process::timeout(60)->run(['gh', 'pr', 'view', $build->pr_url, '--json', 'state']);
            $state = json_decode($view->output(), true)['state'] ?? null;

            $status = match ($state) {
                'MERGED' => BuildStatus::Merged,
                'CLOSED' => BuildStatus::Closed,
                default => null,
            };

            if ($status !== null) {
                $build->update(['status' => $status]);
                $this->components->twoColumnDetail("#{$build->id} {$build->fleetApp->key}", $status->label());
            }
        });

        return self::SUCCESS;
    }
}
