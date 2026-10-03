<?php

namespace App\Simulation;

use App\Enums\JourneyStatus;
use App\Enums\SimulationStatus;
use App\Models\SimulationRun;
use Faker\Factory as Faker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Walks journeys: records each run and its steps, hands the script to the
 * browser (simulator/run.mjs), and writes each event back as it arrives so
 * the page can follow along. start() leaves the browser running and returns
 * its session, so the worker can keep several journeys going at once;
 * run() is start, wait and finish in one.
 */
class Runner
{
    public function __construct(
        private Simulation $simulation,
        private Findings $findings,
        private LiveCoverage $coverage,
        private Trials $trials,
    ) {}

    public function run(Journey $journey, Persona $persona): SimulationRun
    {
        $session = $this->start($journey, $persona);

        while ($session->poll()) {
            usleep(50_000);
        }

        return $this->finish($session);
    }

    public function start(Journey $journey, Persona $persona): RunSession
    {
        $script = $journey->script($persona, Faker::create());
        $target = config("simulation.targets.{$journey->target()}");

        $run = DB::transaction(function () use ($journey, $persona, $script) {
            $run = SimulationRun::create([
                'journey' => $journey->key(),
                'journey_name' => $journey->name(),
                'target' => $journey->target(),
                'trial' => $journey instanceof StoredJourney && $journey->record->status === JourneyStatus::Trial,
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
                    'target' => Str::limit((string) ($step['target'] ?? $step['path'] ?? $step['text'] ?? ''), 490, '') ?: null,
                    'status' => SimulationStatus::Pending,
                ]);
            }

            return $run;
        });

        $directory = $this->simulation->screenshotDirectory($run);
        File::ensureDirectoryExists($directory);

        $session = new RunSession($run, $journey, $this->simulation);

        $session->attach(Process::path(base_path('simulator'))
            ->timeout((int) config('simulation.run_timeout'))
            ->input(json_encode([
                'base_url' => $target['base_url'],
                'shots_dir' => $directory,
                'step_timeout_ms' => (int) config('simulation.step_timeout_ms'),
                'steps' => $script->steps(),
            ], JSON_THROW_ON_ERROR))
            ->start([config('simulation.node'), config('simulation.runner')], fn (string $type, string $output) => $session->receive($type, $output)));

        return $session;
    }

    /**
     * Close the books on a session whose browser is done: the verdict, the
     * findings, the coverage tally, and — for a drafted journey — its trial.
     */
    public function finish(RunSession $session): SimulationRun
    {
        $run = DB::transaction(function () use ($session) {
            $run = $session->run;
            $status = $session->verdict === SimulationStatus::Passed && $run->steps_passed === $run->steps_total
                ? SimulationStatus::Passed
                : SimulationStatus::Failed;

            // Anything the browser never reached did not run.
            $run->steps()->whereIn('status', [SimulationStatus::Pending, SimulationStatus::Running])
                ->update(['status' => SimulationStatus::Skipped]);

            $run->fill([
                'status' => $status,
                'summary' => $run->summary === null ? null : Str::limit($session->interpolate($run->summary), 250),
                'reached' => $session->reached === [] ? null : $session->reached,
                'error' => $status === SimulationStatus::Failed ? ($run->error ?? 'The browser stopped without reporting a result.') : null,
                'finished_at' => now(),
                'duration_ms' => (int) $run->started_at->diffInMilliseconds(now()),
            ])->save();

            return $run;
        });

        // A journey on trial may fail because it was drafted wrong; its
        // failures are the trial's business, not findings about the app.
        if (! $run->trial) {
            $this->findings->record($run->setRelation('steps', $run->steps()->get()));
        }

        $this->coverage->record($run);

        if ($session->journey instanceof StoredJourney) {
            $this->trials->afterRun($session->journey->record->refresh(), $run);
        }

        return $run;
    }
}
