<?php

namespace App\Jobs;

use App\Enums\ExplorationStatus;
use App\Models\SimulationExploration;
use App\Simulation\Exploring\Explorer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * One exploration, on the builds queue: the long-lived workers that already
 * run Claude Code for test builds.
 */
class RunExploration implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public SimulationExploration $exploration)
    {
        $this->timeout = (int) config('simulation.explore.timeout') + 300;
        $this->onConnection(config('scanner.queue.connection'));
        $this->onQueue('builds');
    }

    public function handle(Explorer $explorer): void
    {
        if ($this->exploration->status !== ExplorationStatus::Queued) {
            return;
        }

        $explorer->explore($this->exploration);
    }

    public function failed(?Throwable $e): void
    {
        $this->exploration->refresh();

        if ($this->exploration->status->isActive()) {
            $this->exploration->update([
                'status' => ExplorationStatus::Failed,
                'error' => 'The worker stopped before the exploration finished'.($e ? ': '.$e->getMessage() : '.'),
                'finished_at' => now(),
            ]);
        }
    }
}
