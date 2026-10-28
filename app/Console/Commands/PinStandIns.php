<?php

namespace App\Console\Commands;

use App\Simulation\StandInPins;
use Illuminate\Console\Command;

class PinStandIns extends Command
{
    protected $signature = 'tests:pin-stand-ins
                            {fleet? : The stand-in fleet (simulation.fleets key; default this install\'s, simulation.fleet)}
                            {--json : Print the result as JSON}';

    protected $description = 'Move each stand-in checkout to the release or commit its fleet runs live, so journeys walk what clients use';

    public function handle(StandInPins $pins): int
    {
        $fleet = (string) ($this->argument('fleet') ?? config('simulation.fleet'));

        if (! is_array(config("simulation.fleets.{$fleet}"))) {
            $this->components->error("No stand-in fleet \"{$fleet}\". Known: ".implode(', ', array_keys((array) config('simulation.fleets'))).'.');

            return self::FAILURE;
        }

        $results = $pins->pin($fleet);

        if ($this->option('json')) {
            $this->line((string) json_encode(['fleet' => $fleet, 'apps' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($results as $result) {
                $this->components->twoColumnDetail(
                    $result['app'].($result['live'] ? " <fg=gray>{$result['live']}</>" : ''),
                    match ($result['status']) {
                        'moved' => '<fg=green>moved</> ',
                        'current' => '<fg=green>current</> ',
                        'unknown' => '<fg=gray>unknown</> ',
                        default => "<fg=red>{$result['status']}</> ",
                    }.$result['detail'],
                );
            }
        }

        return collect($results)->contains(fn (array $r) => in_array($r['status'], ['failed', 'refused'], true)) ? self::FAILURE : self::SUCCESS;
    }
}
