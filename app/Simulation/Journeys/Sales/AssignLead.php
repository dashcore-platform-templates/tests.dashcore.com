<?php

namespace App\Simulation\Journeys\Sales;

use App\Simulation\Persona;
use App\Simulation\Script;
use Faker\Generator;

/**
 * The owner hands an unowned lead to one of the reps.
 */
class AssignLead extends SalesJourney
{
    public function key(): string
    {
        return 'sales.assign-lead';
    }

    public function name(): string
    {
        return 'Hand out a lead';
    }

    public function roles(): array
    {
        return ['Owner'];
    }

    public function script(Persona $persona, Generator $faker): Script
    {
        $who = $persona->firstName();
        $rep = $faker->randomElement(array_column(
            array_filter(config('simulation.targets.sales.personas'), fn (array $p) => $p['role'] === 'Sales rep'),
            'name',
        ));

        return $this->signIn(new Script, $persona)
            ->visit('/leads?view=unassigned', "{$who} opens the leads nobody owns")
            ->remember('tbody tr:first-child a[href*="/leads/"]', 'lead', "{$who} picks the first one")
            ->choose('tbody tr:first-child select[aria-label="Owner"]', $rep, "{$who} gives {lead} to {$rep}")
            ->see('Owner updated.', "{$who} sees the owner was updated")
            ->summary("{$who} gave {lead} to {$rep}");
    }
}
