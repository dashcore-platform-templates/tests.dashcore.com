<?php

namespace App\Console\Commands;

use App\Enums\SimulationStatus;
use App\Simulation\Runner;
use App\Simulation\Simulation;
use Illuminate\Console\Command;
use Illuminate\Console\View\TaskResult;
use Illuminate\Support\Sleep;
use Symfony\Component\Console\Command\SignalableCommandInterface;

/**
 * The Proving Ground's worker. Runs journey after journey while the page's
 * switch is on and idles while it is off. Kept alive by the launchd agent
 * com.dashcore.simulation-tests — never started from the schedule, because
 * launchd ends everything a schedule tick starts when the tick ends.
 */
class Simulate extends Command implements SignalableCommandInterface
{
    protected $signature = 'tests:simulate
        {--once : Run one journey now, whether or not the simulation is on, then exit}
        {--journey= : Run this journey (by key) instead of drawing one}
        {--max-runs= : Exit after this many runs so launchd starts a fresh worker}';

    protected $description = 'Run the Proving Ground: fake people working through the fake company\'s apps';

    private bool $stopping = false;

    public function handle(Simulation $simulation, Runner $runner): int
    {
        $recovered = $simulation->recoverInterrupted();

        if ($recovered > 0) {
            $this->components->warn("{$recovered} run(s) left running by a previous worker marked stopped.");
        }

        $once = (bool) $this->option('once');
        $maxRuns = (int) ($this->option('max-runs') ?? config('simulation.max_runs'));
        $runs = 0;

        while (! $this->stopping) {
            $simulation->heartbeat();

            if (! $once && ! $simulation->isOn()) {
                $this->idle(2, $simulation);

                continue;
            }

            [$journey, $persona] = $simulation->pick($this->option('journey'));
            $this->components->task("{$persona->name}: {$journey->name()}", function () use ($runner, $journey, $persona, &$run) {
                $run = $runner->run($journey, $persona);

                return ($run->status === SimulationStatus::Passed ? TaskResult::Success : TaskResult::Failure)->value;
            });

            if ($run->error) {
                $this->line('  <fg=red>'.$run->error.'</>');
            }

            $simulation->prune();
            $runs++;

            if ($once || ($maxRuns > 0 && $runs >= $maxRuns)) {
                break;
            }

            $this->idle((int) config('simulation.pause'), $simulation);
        }

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    public function getSubscribedSignals(): array
    {
        return [SIGINT, SIGTERM];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        // Let the journey in flight finish; stop before the next one.
        $this->stopping = true;

        return false;
    }

    private function idle(int $seconds, Simulation $simulation): void
    {
        for ($i = 0; $i < $seconds && ! $this->stopping; $i++) {
            Sleep::for(1)->second();
            $simulation->heartbeat();
        }
    }
}
