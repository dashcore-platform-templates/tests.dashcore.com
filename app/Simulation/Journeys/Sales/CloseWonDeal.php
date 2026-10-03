<?php

namespace App\Simulation\Journeys\Sales;

use App\Simulation\Persona;
use App\Simulation\Script;
use Faker\Generator;

/**
 * The whole sales loop: a new lead, a call logged, a deal opened from the
 * lead, and the deal marked won.
 */
class CloseWonDeal extends SalesJourney
{
    public function key(): string
    {
        return 'sales.close-won-deal';
    }

    public function name(): string
    {
        return 'Lead to won deal';
    }

    /** "won" or "lost": how the deal ends. */
    protected function outcome(): string
    {
        return 'won';
    }

    public function script(Persona $persona, Generator $faker): Script
    {
        $who = $persona->firstName();
        $lead = $this->prospect($faker);
        $amount = $faker->numberBetween(20, 600) * 100;
        $button = ucfirst($this->outcome());

        $script = $this->saveLead($this->addLead($this->signIn(new Script, $persona), $who, $lead), $who);
        $script = $this->openLead($script, $who, $lead);
        $script = $this->logActivity($script, $who, 'call', 'Discovery call: '.$faker->sentence(8));
        $script = $this->createDeal($script, $who, $amount);

        return $script
            ->click("role=button[name=\"{$button}\" s]", "{$who} marks the deal {$this->outcome()}", confirm: true)
            ->gone("role=button[name=\"{$button}\" s]", "{$who} sees the deal closed as {$this->outcome()}")
            ->summary("{$who} {$this->outcome()} a $".number_format($amount)." deal with {$lead['company']}");
    }
}
