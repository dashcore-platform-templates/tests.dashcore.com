<?php

namespace App\Jobs;

use App\Building\Builder;
use App\Enums\BuildStatus;
use App\Models\Build;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one build on the long-running connection, after any scans waiting.
 * One attempt only, for the same reason as RunScan.
 */
class RunBuild implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public Build $build)
    {
        $this->timeout = (int) config('scanner.build.timeout') + (int) config('scanner.timeout') * 2 + 1800;
        $this->onConnection(config('scanner.queue.connection'));
        $this->onQueue('builds');
    }

    public function handle(Builder $builder): void
    {
        if ($this->build->status !== BuildStatus::Queued) {
            return;
        }

        $builder->run($this->build);
    }

    public function failed(?Throwable $e): void
    {
        $this->build->refresh();

        if ($this->build->status->isActive()) {
            $this->build->update([
                'status' => BuildStatus::Failed,
                'error' => 'The build worker stopped before the build finished'.($e ? ': '.$e->getMessage() : '.'),
                'finished_at' => now(),
            ]);
        }
    }
}
