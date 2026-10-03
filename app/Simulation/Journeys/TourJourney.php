<?php

namespace App\Simulation\Journeys;

use App\Simulation\Journey;
use App\Simulation\Persona;
use App\Simulation\Script;
use Faker\Generator;

/**
 * A walk round a target's main pages, from its `tour` config: every page
 * opened, every page checked for its heading. Changes nothing. Every target
 * with a tour gets one, so a new app has a journey from the first minute —
 * the explorer drafts the real work after.
 *
 *   'tour' => [
 *       'roles' => ['HR manager'],
 *       'pages' => [['path' => '/employees', 'see' => 'Employees', 'name' => 'the employees']],
 *   ]
 */
class TourJourney extends Journey
{
    public function __construct(private string $targetKey) {}

    public function key(): string
    {
        return "{$this->targetKey}.tour";
    }

    public function name(): string
    {
        return 'Look around '.config("simulation.targets.{$this->targetKey}.name", $this->targetKey);
    }

    public function target(): string
    {
        return $this->targetKey;
    }

    public function roles(): array
    {
        return config("simulation.targets.{$this->targetKey}.tour.roles")
            ?? array_values(array_unique(array_column(config("simulation.targets.{$this->targetKey}.personas", []), 'role')));
    }

    public function script(Persona $persona, Generator $faker): Script
    {
        $who = $persona->firstName();
        $script = $this->signIn(new Script, $persona);
        $pages = config("simulation.targets.{$this->targetKey}.tour.pages", []);

        foreach ($pages as $page) {
            $name = $page['name'] ?? $page['path'];

            $script
                ->visit($page['path'], "{$who} opens {$name}")
                ->see($page['see'], "{$who} sees {$name}");
        }

        return $script->summary("{$who} looked around ".config("simulation.targets.{$this->targetKey}.name").' — '.count($pages).' pages');
    }
}
