<?php

namespace App\Simulation;

use App\Enums\SimulationStatus;
use App\Models\SimulationRun;
use App\Models\SimulationStep;
use Faker\Factory as Faker;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Walks one journey: records the run and its steps, hands the script to the
 * browser (simulator/run.mjs), and writes each event back as it arrives so
 * the page can follow along.
 */
class Runner
{
    private const CONSOLE_LIMIT = 20;

    public function __construct(private Simulation $simulation) {}

    public function run(Journey $journey, Persona $persona): SimulationRun
    {
        $script = $journey->script($persona, Faker::create());
        $target = config("simulation.targets.{$journey->target()}");

        $run = DB::transaction(function () use ($journey, $persona, $script) {
            $run = SimulationRun::create([
                'journey' => $journey->key(),
                'journey_name' => $journey->name(),
                'target' => $journey->target(),
                'persona_name' => $persona->name,
                'persona_email' => $persona->email,
                'status' => SimulationStatus::Running,
                'summary' => $script->getSummary(),
                'steps_total' => count($script->steps()),
                'started_at' => now(),
            ]);

            foreach ($script->steps() as $position => $step) {
                $run->steps()->create([
                    'position' => $position,
                    'label' => Str::limit($step['label'], 250),
                    'action' => $step['do'],
                    'status' => SimulationStatus::Pending,
                ]);
            }

            return $run;
        });

        $directory = $this->simulation->screenshotDirectory($run);
        File::ensureDirectoryExists($directory);

        $payload = [
            'base_url' => $target['base_url'],
            'shots_dir' => $directory,
            'step_timeout_ms' => (int) config('simulation.step_timeout_ms'),
            'steps' => $script->steps(),
        ];

        $buffer = '';
        $finished = null;
        $stderr = '';

        try {
            $result = Process::path(base_path('simulator'))
                ->timeout((int) config('simulation.run_timeout'))
                ->input(json_encode($payload, JSON_THROW_ON_ERROR))
                ->start([config('simulation.node'), config('simulation.runner')])
                ->wait(function (string $type, string $output) use (&$buffer, &$finished, &$stderr, $run) {
                    if ($type === 'err') {
                        $stderr = Str::substr($stderr.$output, -4000);

                        return;
                    }

                    $buffer .= $output;

                    while (($newline = strpos($buffer, "\n")) !== false) {
                        $line = substr($buffer, 0, $newline);
                        $buffer = substr($buffer, $newline + 1);
                        $finished = $this->handle($run, $line) ?? $finished;
                    }
                });

            if ($buffer !== '') {
                $finished = $this->handle($run, $buffer) ?? $finished;
            }

            if ($finished === null && ! $result->successful()) {
                $run->error ??= trim($stderr) !== '' ? Str::limit(trim($stderr), 2000) : 'The browser runner exited with code '.$result->exitCode().'.';
            }
        } catch (ProcessTimedOutException) {
            $run->error = 'The journey took longer than '.config('simulation.run_timeout').' seconds and was stopped.';
        }

        return $this->finish($run, $finished);
    }

    /**
     * Apply one line from the browser. Returns the run's final status when
     * the line is the runner's last word.
     */
    private function handle(SimulationRun $run, string $line): ?SimulationStatus
    {
        $event = json_decode(trim($line), true);

        if (! is_array($event)) {
            return null;
        }

        $step = isset($event['position'])
            ? SimulationStep::query()->where('simulation_run_id', $run->id)->where('position', (int) $event['position'])->first()
            : null;

        switch ($event['event'] ?? null) {
            case 'step':
                $status = SimulationStatus::tryFrom($event['status'] ?? '') ?? SimulationStatus::Failed;

                $step?->update(array_filter([
                    'status' => $status,
                    'duration_ms' => $event['ms'] ?? null,
                    'url' => $event['url'] ?? null,
                    'screenshot' => $event['screenshot'] ?? null,
                    'error' => $event['error'] ?? null,
                ], fn ($value) => $value !== null));

                $run->current_step = (int) $event['position'];

                if ($status === SimulationStatus::Passed) {
                    $run->steps_passed++;
                }

                if ($status === SimulationStatus::Failed) {
                    $run->error = $step ? "{$step->label}: ".($event['error'] ?? 'failed') : ($event['error'] ?? 'A step failed.');
                }

                $run->save();
                $this->simulation->heartbeat();

                return null;

            case 'console':
                if ($step !== null && count($step->console ?? []) < self::CONSOLE_LIMIT) {
                    $step->update(['console' => [...($step->console ?? []), [
                        'level' => (string) ($event['level'] ?? 'error'),
                        'text' => Str::limit((string) ($event['text'] ?? ''), 1000),
                    ]]]);
                }

                return null;

            case 'error':
                $run->update(['error' => Str::limit((string) ($event['text'] ?? 'The browser failed.'), 2000)]);

                return null;

            case 'finished':
                return SimulationStatus::tryFrom($event['status'] ?? '') ?? SimulationStatus::Failed;
        }

        return null;
    }

    private function finish(SimulationRun $run, ?SimulationStatus $finished): SimulationRun
    {
        return DB::transaction(function () use ($run, $finished) {
            $status = $finished === SimulationStatus::Passed && $run->steps_passed === $run->steps_total
                ? SimulationStatus::Passed
                : SimulationStatus::Failed;

            // Anything the browser never reached did not run.
            $run->steps()->whereIn('status', [SimulationStatus::Pending, SimulationStatus::Running])
                ->update(['status' => SimulationStatus::Skipped]);

            $run->fill([
                'status' => $status,
                'error' => $status === SimulationStatus::Failed ? ($run->error ?? 'The browser stopped without reporting a result.') : null,
                'finished_at' => now(),
                'duration_ms' => (int) $run->started_at->diffInMilliseconds(now()),
            ])->save();

            return $run;
        });
    }
}
