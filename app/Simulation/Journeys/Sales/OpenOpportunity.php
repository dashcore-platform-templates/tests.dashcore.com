<?php

namespace App\Simulation\Journeys\Sales;

use App\Simulation\Persona;
use App\Simulation\Script;
use Faker\Generator;

/**
 * A meeting goes well: the rep opens a deal and books the next step, and the
 * deal stays in the pipeline for later journeys to move along.
 */
class OpenOpportunity extends SalesJourney
{
    public function key(): string
    {
        return 'sales.open-opportunity';
    }

    public function name(): string
    {
        return 'Open an opportunity';
    }

    public function script(Persona $persona, Generator $faker): Script
    {
        $who = $persona->firstName();
        $lead = $this->prospect($faker);
        $amount = $faker->numberBetween(30, 900) * 100;
        $next = 'Send proposal to '.$lead['name'];

        $script = $this->saveLead($this->addLead($this->signIn(new Script, $persona), $who, $lead, everything: true), $who);
        $script = $this->openLead($script, $who, $lead);
        $script = $this->logActivity($script, $who, 'meeting', 'Intro meeting: '.$faker->sentence(9));
        $script = $this->createDeal($script, $who, $amount);

        return $script
            ->fill('input[wire\:model="newTaskTitle"]', $next, "{$who} books the next step: {$next}")
            ->fill('input[wire\:model="newTaskDueAt"]', now()->addDays($faker->numberBetween(1, 7))->setTime(10, 0)->format('Y-m-d\TH:i'), "{$who} sets it for later this week")
            ->click('form[wire\:submit\.prevent="addTask"] button[type=submit]', "{$who} adds the follow-up")
            ->see('Task added.', "{$who} sees the follow-up was added")
            ->summary("{$who} opened a $".number_format($amount)." opportunity with {$lead['company']}");
    }
}
