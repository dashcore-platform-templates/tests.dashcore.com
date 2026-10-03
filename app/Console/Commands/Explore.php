<?php

namespace App\Console\Commands;

use App\Enums\ExplorationStatus;
use App\Jobs\RunExploration;
use App\Models\SimulationExploration;
use App\Simulation\Exploring\Explorer;
use Illuminate\Console\Command;

/**
 * Asks the explorer to draft new journeys for a target app. They go on trial
 * in the Proving Ground and join the rotation once they keep passing.
 */
class Explore extends Command
{
    protected $signature = 'tests:explore
        {target=sales : The simulation target to explore}
        {--focus= : What to look at, in plain words}
        {--now : Run in this process instead of queueing}';

    protected $description = 'Draft new Proving Ground journeys from an app\'s source';

    public function handle(Explorer $explorer): int
    {
        $target = (string) $this->argument('target');

        if (config("simulation.targets.{$target}") === null) {
            $this->components->error("No simulation target \"{$target}\".");

            return self::FAILURE;
        }

        $exploration = SimulationExploration::create([
            'target' => $target,
            'focus' => $this->option('focus'),
            'status' => ExplorationStatus::Queued,
        ]);

        if (! $this->option('now')) {
            RunExploration::dispatch($exploration);
            $this->components->info("Exploration #{$exploration->id} of {$target} queued.");

            return self::SUCCESS;
        }

        $exploration = $explorer->explore($exploration);

        if ($exploration->status === ExplorationStatus::Failed) {
            $this->components->error($exploration->error);

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            '%d journey(s) drafted and on trial, %d turned away%s.',
            $exploration->drafted,
            $exploration->rejected,
            $exploration->cost_usd !== null ? sprintf(', $%.2f', $exploration->cost_usd) : '',
        ));

        if ($exploration->report) {
            $this->line($exploration->report);
        }

        return self::SUCCESS;
    }
}
