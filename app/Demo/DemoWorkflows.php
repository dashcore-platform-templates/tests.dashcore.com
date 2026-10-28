<?php

namespace App\Demo;

use App\Enums\SimulationStatus;
use App\Enums\WorkflowEventType;
use App\Models\Workflow;
use App\Models\WorkflowEvent;
use App\Models\WorkflowRun;
use App\Workflows\Transfer;
use App\Workflows\WorkflowRunner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * The starter workflows (workflows/starter/), imported for real — switched
 * off, as every import arrives — each with a made-up passed run and a
 * made-up failed one, their placeholder screenshots and log lines. The runs
 * and lines are demo rows; the workflows are not, so a wipe keeps them.
 */
class DemoWorkflows
{
    /**
     * Where each starter's demo failure happens: [step position, error].
     *
     * @var array<string, array{0: int, 1: string}>
     */
    private const FAILURES = [
        'Website: lets-talk form takes an enquiry' => [1, 'Timed out after 10s waiting for #df-first — the page showed "Something new is coming" instead of the form.'],
        'Website: main pages answer' => [3, 'Timed out after 10s waiting for the text "Privacy Policy" — the page answered 502.'],
        'Sales stand-in: a rep signs in' => [4, 'Timed out after 10s waiting for the text "Dashboard" — the page said "These credentials do not match our records."'],
        'Sales stand-in: the leads list opens' => [6, 'Timed out after 10s waiting for the text "Lead board" — the page answered 500.'],
    ];

    /**
     * The starter workflows, importing any not here yet.
     *
     * @return list<Workflow>
     */
    public static function starters(): array
    {
        $workflows = [];

        foreach (File::glob(base_path('workflows/starter/*.json')) as $path) {
            $json = (string) File::get($path);
            $name = json_decode($json, true)['workflows'][0]['name'];

            $workflows[] = Workflow::query()->where('name', $name)->first()
                ?? Transfer::import($json, source: basename($path))->sole();
        }

        return $workflows;
    }

    /**
     * @return int starter workflows with demo runs
     */
    public function seed(): int
    {
        $starters = self::starters();

        foreach ($starters as $i => $workflow) {
            $failFirst = $i % 2 === 0;
            $earlier = Carbon::now()->subDays(2)->subHours($i * 3 + 1);
            $later = Carbon::now()->subHours($i * 2 + 1);

            $this->run($workflow, $earlier, passed: ! $failFirst, trigger: WorkflowRun::TRIGGER_MANUAL);
            $last = $this->run($workflow, $later, passed: $failFirst, trigger: WorkflowRun::TRIGGER_SCHEDULE);

            $workflow->update([
                'last_status' => $last->status,
                'last_run_at' => $last->finished_at,
                'consecutive_failures' => $last->status === SimulationStatus::Passed ? 0 : 1,
            ]);
        }

        return count($starters);
    }

    /**
     * Put the starters back as they were before the demo ran them: their
     * latest real run's result, or none. Called after the demo runs go.
     *
     * @param  list<int>  $workflowIds
     */
    public static function settle(array $workflowIds): void
    {
        foreach (Workflow::query()->whereIn('id', $workflowIds)->get() as $workflow) {
            $latest = $workflow->runs()->latest('id')->first();

            $workflow->update([
                'last_status' => $latest?->status,
                'last_run_at' => $latest?->finished_at,
                'consecutive_failures' => 0,
                'alerting' => false,
            ]);
        }
    }

    private function run(Workflow $workflow, Carbon $started, bool $passed, string $trigger): WorkflowRun
    {
        [$breaksAt, $error] = self::FAILURES[$workflow->name] ?? [0, 'Timed out after 10s.'];
        $steps = $workflow->steps;
        $breaksAt = $passed ? null : min($breaksAt, count($steps) - 1);
        $ms = 0;

        $run = $workflow->runs()->create([
            'status' => $passed ? SimulationStatus::Passed : SimulationStatus::Failed,
            'trigger' => $trigger,
            'steps_total' => count($steps),
            'steps_passed' => $passed ? count($steps) : $breaksAt,
            'error' => $passed ? null : 'Step '.($breaksAt + 1).' failed: '.$error,
            'is_demo' => true,
            'started_at' => $started,
            'created_at' => $started,
            'updated_at' => $started,
        ]);

        foreach ($steps as $position => $step) {
            $status = match (true) {
                $breaksAt === null || $position < $breaksAt => 'passed',
                $position === $breaksAt => 'failed',
                default => 'skipped',
            };
            $stepMs = match ($status) {
                'passed' => $step['do'] === 'visit' ? 900 + 37 * $position : 180 + 23 * $position,
                'failed' => 10000,
                default => null,
            };
            $ms += (int) $stepMs;
            $url = $workflow->base_url.($step['do'] === 'visit' ? $step['path'] : '');
            $shot = $status === 'skipped' ? null : sprintf('%03d.jpg', $position);

            if ($shot !== null) {
                Placeholder::write(WorkflowRunner::screenshotDirectory($run).'/'.$shot, $url, (string) $step['label'], $status === 'failed');
            }

            $run->steps()->create([
                'position' => $position,
                'label' => $step['label'],
                'action' => $step['do'],
                'target' => $step['target'] ?? $step['path'] ?? $step['text'] ?? $step['key'] ?? null,
                'status' => $status,
                'duration_ms' => $stepMs,
                'url' => $shot === null ? null : $url,
                'screenshot' => $shot,
                'error' => $status === 'failed' ? $error : null,
            ]);
        }

        $run->update(['duration_ms' => $ms, 'finished_at' => $started->copy()->addMilliseconds($ms)]);

        $how = $trigger === WorkflowRun::TRIGGER_MANUAL ? 'run by hand' : 'on schedule';
        $took = number_format($ms / 1000, 1).'s';

        WorkflowEvent::query()->create([
            'type' => $passed ? WorkflowEventType::RunPassed : WorkflowEventType::RunFailed,
            'workflow_id' => $workflow->id,
            'workflow_name' => $workflow->name,
            'workflow_run_id' => $run->id,
            'message' => $passed
                ? "Passed all {$run->steps_total} steps in {$took}, {$how}. (Demo run — nothing was opened.)"
                : "Failed after {$run->steps_passed} of {$run->steps_total} steps ({$took}, {$how}): {$run->error} (Demo run — nothing was opened.)",
            'is_demo' => true,
            'created_at' => $run->finished_at,
        ]);

        return $run;
    }
}
