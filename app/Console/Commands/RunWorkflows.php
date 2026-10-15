<?php

namespace App\Console\Commands;

use App\Enums\SimulationStatus;
use App\Enums\WorkflowEventType;
use App\Models\Workflow;
use App\Models\WorkflowEvent;
use App\Models\WorkflowRun;
use App\Workflows\EventLog;
use App\Workflows\Heartbeat;
use App\Workflows\WorkflowRunner;
use Carbon\CarbonInterface;
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

    public function handle(WorkflowRunner $runner, Heartbeat $heartbeat): int
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

        $this->noticeScheduleGap();

        $walked = 0;
        $broken = [];

        try {
            foreach (Workflow::query()->due()->orderBy('id')->pluck('id') as $id) {
                [$workflow, $trigger] = $this->claim($id) ?? [null, null];

                if ($workflow === null) {
                    continue;
                }

                try {
                    $run = $runner->run($workflow, $trigger);
                    $walked++;
                    $this->report($run, $workflow);

                    if ($this->browserNeverRan($run)) {
                        $broken[] = "{$workflow->name}: the browser never ran a step — {$run->error}";
                        EventLog::record(WorkflowEventType::CouldNotRun, $workflow, "The browser never ran a step, so this machine — not the site — is at fault: {$run->error}", $run);
                    }
                } catch (Throwable $e) {
                    report($e);
                    $this->error("{$workflow->name}: {$e->getMessage()}");
                    $broken[] = "{$workflow->name}: {$e->getMessage()}";
                    EventLog::record(WorkflowEventType::CouldNotRun, $workflow, "The run could not start: {$e->getMessage()}");
                }
            }

            $this->prune();
        } catch (Throwable $e) {
            report($e);
            $broken[] = 'tests:workflows failed: '.$e->getMessage();
        }

        $heartbeat->beat($broken === [], $broken === []
            ? ($walked === 0 ? 'Tick: nothing due.' : "Tick: walked {$walked} workflow(s).")
            : implode("\n", $broken));

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

    /**
     * A failed run in which the browser reported no step at all: it never
     * started (no Chromium, Playwright missing) or died before the first
     * page. That is this machine's fault, not the site's.
     */
    private function browserNeverRan(WorkflowRun $run): bool
    {
        return $run->status !== SimulationStatus::Passed
            && $run->steps()->where('status', '!=', SimulationStatus::Skipped)->doesntExist();
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
            ->with('workflow')
            ->where('status', SimulationStatus::Running)
            ->where('started_at', '<', now()->subSeconds((int) config('workflows.run_timeout') * 2 + 60))
            ->get()
            ->each(function (WorkflowRun $run) {
                DB::transaction(function () use ($run) {
                    $run->update([
                        'status' => SimulationStatus::Stopped,
                        'error' => 'The run was cut off before it finished.',
                        'finished_at' => now(),
                    ]);

                    EventLog::record(WorkflowEventType::RunStopped, $run->workflow, 'Cut off before it finished — the machine slept or the process was killed — after starting '.$run->started_at->diffForHumans().'.', $run);
                });
            });
    }

    /**
     * When the schedule comes back after not running (the Mac asleep, off,
     * or the scheduler broken), log how long nothing was checked.
     */
    private function noticeScheduleGap(): void
    {
        $last = Heartbeat::lastTick();

        if ($last === null || $last->gte(now()->subMinutes((int) config('workflows.stale_after_minutes')))) {
            return;
        }

        if (Workflow::query()->where('active', true)->doesntExist()) {
            return;
        }

        EventLog::record(WorkflowEventType::ScheduleResumed, null, sprintf(
            'The schedule did not run for %s (%s to %s), so no workflow was checked.',
            $last->diffForHumans(now(), syntax: CarbonInterface::DIFF_ABSOLUTE, parts: 2),
            $last->format('M j H:i'),
            now()->format('M j H:i'),
        ));
    }

    /**
     * Each workflow keeps pictures for its newest runs only, and no run
     * record outlives prune_days. The log keeps its lines for event_days,
     * longer than the runs, so a year's health can still be read.
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

        WorkflowEvent::query()
            ->where('created_at', '<', now()->subDays(max(1, (int) config('workflows.event_days'))))
            ->delete();
    }
}
