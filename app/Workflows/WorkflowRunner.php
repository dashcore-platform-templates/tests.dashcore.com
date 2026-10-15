<?php

namespace App\Workflows;

use App\Enums\SimulationStatus;
use App\Enums\WorkflowEventType;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowRunStep;
use Faker\Factory as Faker;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Walks one workflow in a real browser — the Proving Ground's runner,
 * simulator/run.mjs — writing each step back as the browser reports it,
 * then settles the workflow: its last result, its next turn, and an alert
 * when it breaks or comes back.
 */
class WorkflowRunner
{
    private string $buffer = '';

    private ?SimulationStatus $verdict = null;

    public function __construct(private Alerts $alerts) {}

    public function run(Workflow $workflow, string $trigger): WorkflowRun
    {
        $steps = Steps::script($workflow->steps, Faker::create());

        $run = DB::transaction(function () use ($workflow, $steps, $trigger) {
            $run = $workflow->runs()->create([
                'status' => SimulationStatus::Running,
                'trigger' => $trigger,
                'steps_total' => count($steps),
                'started_at' => now(),
            ]);

            foreach ($steps as $position => $step) {
                $run->steps()->create([
                    'position' => $position,
                    'label' => Str::limit($step['label'], 250),
                    'action' => $step['do'],
                    'target' => Str::limit((string) ($step['target'] ?? $step['path'] ?? $step['text'] ?? $step['key'] ?? ''), 490, '') ?: null,
                    'status' => SimulationStatus::Pending,
                ]);
            }

            return $run;
        });

        $this->walk($run, $workflow, $steps);

        return $this->finish($workflow, $run);
    }

    public static function screenshotDirectory(WorkflowRun $run): string
    {
        return rtrim((string) config('workflows.screenshots'), '/').'/'.$run->id;
    }

    /**
     * @param  list<array<string, string>>  $steps
     */
    private function walk(WorkflowRun $run, Workflow $workflow, array $steps): void
    {
        $this->buffer = '';
        $this->verdict = null;
        $stderr = '';

        $directory = self::screenshotDirectory($run);
        File::ensureDirectoryExists($directory);

        $process = Process::path(base_path('simulator'))
            ->timeout((int) config('workflows.run_timeout'))
            ->input(json_encode([
                'base_url' => $workflow->base_url,
                'shots_dir' => $directory,
                'step_timeout_ms' => (int) config('workflows.step_timeout_ms'),
                'steps' => $steps,
            ], JSON_THROW_ON_ERROR))
            ->start([config('simulation.node'), config('simulation.runner')], function (string $type, string $output) use ($run, &$stderr) {
                if ($type === 'err') {
                    $stderr = Str::substr($stderr.$output, -4000);

                    return;
                }

                $this->receive($run, $output);
            });

        try {
            while ($process->running()) {
                $process->ensureNotTimedOut();
                usleep(100_000);
            }
        } catch (ProcessTimedOutException) {
            $process->stop(2);
            $run->error = 'The workflow took longer than '.config('workflows.run_timeout').' seconds and was stopped.';

            return;
        }

        $result = $process->wait();

        if ($this->buffer !== '') {
            $this->handle($run, $this->buffer);
            $this->buffer = '';
        }

        if ($this->verdict === null && ! $result->successful()) {
            $run->error ??= trim($stderr) !== '' ? Str::limit(trim($stderr), 2000) : 'The browser runner exited with code '.$result->exitCode().'.';
        }
    }

    private function receive(WorkflowRun $run, string $output): void
    {
        $this->buffer .= $output;

        while (($newline = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $newline);
            $this->buffer = substr($this->buffer, $newline + 1);
            $this->handle($run, $line);
        }
    }

    private function handle(WorkflowRun $run, string $line): void
    {
        $event = json_decode(trim($line), true);

        if (! is_array($event)) {
            return;
        }

        switch ($event['event'] ?? null) {
            case 'step':
                $status = SimulationStatus::tryFrom($event['status'] ?? '') ?? SimulationStatus::Failed;
                $step = WorkflowRunStep::query()
                    ->where('workflow_run_id', $run->id)
                    ->where('position', (int) ($event['position'] ?? -1))
                    ->first();

                $step?->update(array_filter([
                    'status' => $status,
                    'duration_ms' => $event['ms'] ?? null,
                    'url' => $event['url'] ?? null,
                    'screenshot' => $event['screenshot'] ?? null,
                    'error' => isset($event['error']) ? Str::limit((string) $event['error'], 2000) : null,
                ], fn ($value) => $value !== null));

                if ($status === SimulationStatus::Passed) {
                    $run->steps_passed++;
                }

                if ($status === SimulationStatus::Failed) {
                    $run->error = Str::limit(($step ? "{$step->label}: " : '').($event['error'] ?? 'failed'), 2000);
                }

                $run->save();

                return;

            case 'error':
                $run->error = Str::limit((string) ($event['text'] ?? 'The browser failed.'), 2000);
                $run->save();

                return;

            case 'finished':
                $this->verdict = SimulationStatus::tryFrom($event['status'] ?? '') ?? SimulationStatus::Failed;
        }
    }

    private function finish(Workflow $workflow, WorkflowRun $run): WorkflowRun
    {
        [$run, $workflow, $alert] = DB::transaction(function () use ($workflow, $run) {
            $passed = $this->verdict === SimulationStatus::Passed && $run->steps_passed === $run->steps_total;
            $status = $passed ? SimulationStatus::Passed : SimulationStatus::Failed;

            // Anything the browser never reached did not run.
            $run->steps()->whereIn('status', [SimulationStatus::Pending, SimulationStatus::Running])
                ->update(['status' => SimulationStatus::Skipped]);

            $run->fill([
                'status' => $status,
                'error' => $passed ? null : ($run->error ?? 'The browser stopped without reporting a result.'),
                'finished_at' => now(),
                'duration_ms' => (int) $run->started_at->diffInMilliseconds(now()),
            ])->save();

            $workflow = Workflow::query()->lockForUpdate()->findOrFail($workflow->id);
            $failures = $passed ? 0 : $workflow->consecutive_failures + 1;

            // One alert when it breaks (after alert_after failures in a row,
            // so a single blip stays quiet) and one when it comes back.
            $alert = match (true) {
                ! $passed && ! $workflow->alerting && $failures >= max(1, $workflow->alert_after) => Alerts::BROKE,
                $passed && $workflow->alerting => Alerts::RECOVERED,
                default => null,
            };

            $workflow->fill([
                'last_status' => $status,
                'last_run_at' => now(),
                'consecutive_failures' => $failures,
                'alerting' => $alert === Alerts::BROKE ? true : ($alert === Alerts::RECOVERED ? false : $workflow->alerting),
            ])->save();

            EventLog::record($passed ? WorkflowEventType::RunPassed : WorkflowEventType::RunFailed, $workflow, self::summary($run), $run);

            return [$run, $workflow, $alert];
        });

        if ($alert !== null) {
            $this->alerts->send($workflow, $run, $alert);
        }

        return $run;
    }

    /**
     * One line for the log: how far it got, how long it took, who asked.
     */
    private static function summary(WorkflowRun $run): string
    {
        $how = $run->trigger === WorkflowRun::TRIGGER_MANUAL ? 'run by hand' : 'on schedule';
        $took = number_format($run->duration_ms / 1000, 1).'s';

        return $run->status === SimulationStatus::Passed
            ? "Passed all {$run->steps_total} steps in {$took}, {$how}."
            : "Failed after {$run->steps_passed} of {$run->steps_total} steps ({$took}, {$how}): {$run->error}";
    }
}
