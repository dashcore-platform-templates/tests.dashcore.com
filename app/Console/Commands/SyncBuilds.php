<?php

namespace App\Console\Commands;

use App\Enums\BuildStatus;
use App\Models\Build;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Follows the PRs builds opened. A merged PR shows up as coverage on the next
 * scan; a closed one — or a local branch that was deleted — frees its targets
 * for another build.
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

        // A build kept on a local branch holds its targets until the branch is
        // merged by hand or deleted; once it is gone, they are free again.
        Build::query()->with('fleetApp')->where('status', BuildStatus::Ready)->whereNotNull('branch')->each(function (Build $build) {
            $repo = $build->fleetApp?->local_path;

            if ($repo === null || ! is_dir($repo)) {
                return;
            }

            $exists = Process::path($repo)->run(['git', 'rev-parse', '--verify', '--quiet', 'refs/heads/'.$build->branch])->successful();

            if (! $exists) {
                $build->update([
                    'status' => BuildStatus::Closed,
                    'notes' => [...($build->notes ?? []), "Branch {$build->branch} is gone, so its targets are free for another build."],
                ]);
                $this->components->twoColumnDetail("#{$build->id} {$build->fleetApp->key}", 'branch gone');
            }
        });

        return self::SUCCESS;
    }
}
