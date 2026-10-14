<?php

namespace App\Console\Commands;

use App\Enums\SimulationStatus;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Workflows\WorkflowRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Walks every workflow whose turn has come, one after another, right here
 * in the schedule's own process. No queue worker is involved: a walk takes
 * seconds to a minute, and launchd ends anything a tick leaves running in
 * the background, so nothing is started that would outlive the tick.
 */
class RunWorkflows extends Command
{
    protected $signature = 'tests:workflows {workflow? : Run this workflow now, whatever its schedule}';

    protected $description = 'Walk the browser-test workflows that are due (or one, now)';

    public function handle(WorkflowRunner $runner): int
    {
        $this->settleAbandonedRuns();

        if ($id = $this->argument('workflow')) {
            $workflow = Workflow::query()->find($id);

            if ($workflow === null) {
                $this->error("No workflow {$id}.");

                return self::FAILURE;
            }

            $workflow->update(['run_requested_at' => null]);
            $this->report($runner->run($workflow, WorkflowRun::TRIGGER_MANUAL), $workflow);

            return self::SUCCESS;
        }

        foreach (Workflow::query()->due()->orderBy('id')->pluck('id') as $id) {
            [$workflow, $trigger] = $this->claim($id) ?? [null, null];

            if ($workflow === null) {
                continue;
            }

            try {
                $this->report($runner->run($workflow, $trigger), $workflow);
            } catch (Throwable $e) {
                report($e);
                $this->error("{$workflow->name}: {$e->getMessage()}");
            }
        }

        $this->prune();

        return self::SUCCESS;
    }

    /**
     * Take a due workflow's turn: clear its request and book its next turn
     * before walking, so an overlapping tick never walks it twice.
     *
     * @return array{Workflow, string}|null
     */
    private function claim(int $id): ?array
    {
        return DB::transaction(function () use ($id) {
            $workflow = Workflow::query()->due()->lockForUpdate()->find($id);

            if ($workflow === null) {
                return null;
            }

            $trigger = $workflow->run_requested_at !== null ? WorkflowRun::TRIGGER_MANUAL : WorkflowRun::TRIGGER_SCHEDULE;

            $workflow->update([
                'run_requested_at' => null,
                'next_run_at' => $workflow->nextScheduledRun(),
            ]);

            return [$workflow, $trigger];
        });
    }

    private function report(WorkflowRun $run, Workflow $workflow): void
    {
        $run->status === SimulationStatus::Passed
            ? $this->info("{$workflow->name}: passed ({$run->steps_passed}/{$run->steps_total})")
            : $this->warn("{$workflow->name}: failed — {$run->error}");
    }

    /**
     * A run still "running" long after any walk could last was cut off (the
     * machine slept, the process was killed). Say so rather than leave it
     * spinning on the page for ever.
     */
    private function settleAbandonedRuns(): void
    {
        WorkflowRun::query()
            ->where('status', SimulationStatus::Running)
            ->where('started_at', '<', now()->subSeconds((int) config('workflows.run_timeout') * 2 + 60))
            ->update([
                'status' => SimulationStatus::Stopped,
                'error' => 'The run was cut off before it finished.',
                'finished_at' => now(),
            ]);
    }

    /**
     * Each workflow keeps pictures for its newest runs only, and no run
     * record outlives prune_days.
     */
    private function prune(): void
    {
        $keep = max(1, (int) config('workflows.keep_runs'));

        Workflow::query()->pluck('id')->each(function (int $id) use ($keep) {
            WorkflowRun::query()
                ->where('workflow_id', $id)
                ->where('screenshots_pruned', false)
                ->whereNotIn('status', [SimulationStatus::Running])
                ->orderByDesc('id')
                ->offset($keep)
                ->limit(PHP_INT_MAX)
                ->get()
                ->each(function (WorkflowRun $run) {
                    File::deleteDirectory(WorkflowRunner::screenshotDirectory($run));
                    $run->update(['screenshots_pruned' => true]);
                });
        });

        WorkflowRun::query()
            ->where('created_at', '<', now()->subDays(max(1, (int) config('workflows.prune_days'))))
            ->get()
            ->each(function (WorkflowRun $run) {
                File::deleteDirectory(WorkflowRunner::screenshotDirectory($run));
                $run->delete();
            });
    }
}
