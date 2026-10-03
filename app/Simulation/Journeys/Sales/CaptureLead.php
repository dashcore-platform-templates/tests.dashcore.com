<?php

namespace App\Simulation\Journeys\Sales;

use App\Simulation\Persona;
use App\Simulation\Script;
use Faker\Generator;

/**
 * A rep adds a lead by hand and sees it arrive at the top of the list.
 */
class CaptureLead extends SalesJourney
{
    public function key(): string
    {
        return 'sales.capture-lead';
    }

    public function name(): string
    {
        return 'Capture a lead';
    }

    public function script(Persona $persona, Generator $faker): Script
    {
        $who = $persona->firstName();
        $lead = $this->prospect($faker);

        $script = $this->addLead($this->signIn(new Script, $persona), $who, $lead, everything: true)
            ->fill('#newMessage', $faker->sentence(12), "{$who} notes what they asked for");

        return $this->saveLead($script, $who)
            ->see($lead['name'], "{$who} sees {$lead['name']} in the list")
            ->summary("{$who} added lead {$lead['name']} from {$lead['company']}");
    }
}
