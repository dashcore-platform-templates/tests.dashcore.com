<?php

namespace App\Console\Commands;

use App\Enums\SimulationStatus;
use App\Models\SimulationRun;
use App\Simulation\Monitor;
use App\Simulation\Runner;
use App\Simulation\RunSession;
use App\Simulation\Simulation;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Throwable;

/**
 * The Proving Ground's worker. While the page's switch is on it keeps as many
 * journeys in flight as the page asks for — people working side by side, each
 * in their own browser — and idles while it is off. Kept alive by the launchd
 * agent com.dashcore.simulation-tests — never started from the schedule,
 * because launchd ends everything a schedule tick starts when the tick ends.
 */
class Simulate extends Command implements SignalableCommandInterface
{
    protected $signature = 'tests:simulate
        {--once : Run one journey now, whether or not the simulation is on, then exit}
        {--journey= : Run this journey (by key) instead of drawing one}
        {--max-runs= : Exit after this many runs so launchd starts a fresh worker}
        {--max-time= : Exit after this many seconds (once nothing is in flight), so launchd starts a fresh worker on fresh code}';

    protected $description = 'Run the Proving Ground: fake people working through the fake company\'s apps';

    private const TICK_SECONDS = 0.2;

    private const HEARTBEAT_EVERY_SECONDS = 5;

    private bool $stopping = false;

    public function handle(Simulation $simulation, Runner $runner, Monitor $monitor): int
    {
        $recovered = $simulation->recoverInterrupted();

        if ($recovered > 0) {
            $this->components->warn("{$recovered} run(s) left running by a previous worker marked stopped.");
        }

        $once = (bool) $this->option('once');
        $maxRuns = $once ? 1 : (int) ($this->option('max-runs') ?? config('simulation.max_runs'));
        $maxTime = (int) ($this->option('max-time') ?? config('simulation.max_seconds'));
        $startedAt = $this->clock();

        /** @var list<RunSession> $sessions */
        $sessions = [];
        $started = 0;
        $nextStartAt = 0.0;
        $lastBeat = 0.0;

        while (true) {
            $now = $this->clock();

            if ($now - $lastBeat >= self::HEARTBEAT_EVERY_SECONDS) {
                $simulation->heartbeat();
                $lastBeat = $now;
            }

            // Fill the free places, one at a time, while there is room.
            $timeLeft = $once || $maxTime <= 0 || $now - $startedAt < $maxTime;
            $wanted = $this->stopping || ! $timeLeft || (! $once && ! $simulation->isOn()) ? 0 : ($once ? 1 : $simulation->concurrency());
            $budgetLeft = ($maxRuns <= 0 || $started < $maxRuns) && $timeLeft;

            if (count($sessions) < $wanted && $budgetLeft && $now >= $nextStartAt) {
                [$journey, $persona] = $simulation->pick($this->option('journey'));
                $sessions[] = $runner->start($journey, $persona);
                $started++;
                $this->line("  <fg=gray>▸</> {$persona->name}: {$journey->name()}");
            }

            foreach ($sessions as $i => $session) {
                if ($session->poll()) {
                    continue;
                }

                unset($sessions[$i]);
                $this->report($runner->finish($session));
                $nextStartAt = $this->clock() + (int) config('simulation.pause');

                try {
                    $monitor->check($session->run->target);
                    $simulation->prune();
                } catch (Throwable $e) {
                    report($e);
                }
            }

            $sessions = array_values($sessions);

            if ($sessions === [] && ($this->stopping || ! $budgetLeft)) {
                break;
            }

            Sleep::for($sessions === [] && $wanted === 0 ? 1 : self::TICK_SECONDS)->seconds();
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
        // Let the journeys in flight finish; start no more.
        $this->stopping = true;

        return false;
    }

    /**
     * Seconds, from the app's clock rather than the system's, so tests can
     * move it.
     */
    private function clock(): float
    {
        return now()->getTimestampMs() / 1000;
    }

    private function report(SimulationRun $run): void
    {
        $seconds = number_format(($run->duration_ms ?? 0) / 1000, 1);

        if ($run->status === SimulationStatus::Passed) {
            $this->line("  <fg=green>✓</> {$run->persona_name}: {$run->journey_name} <fg=gray>{$seconds}s</>");

            return;
        }

        $this->line("  <fg=red>✗</> {$run->persona_name}: {$run->journey_name} <fg=gray>{$seconds}s</>");
        $this->line('    <fg=red>'.$run->error.'</>');
    }
}
