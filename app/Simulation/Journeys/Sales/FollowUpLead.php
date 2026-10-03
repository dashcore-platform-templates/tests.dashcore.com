<?php

namespace App\Simulation\Journeys\Sales;

use App\Simulation\Persona;
use App\Simulation\Script;
use Faker\Generator;

/**
 * Working the queue: the first lead that has never been contacted gets a
 * touch and a follow-up.
 */
class FollowUpLead extends SalesJourney
{
    public function key(): string
    {
        return 'sales.follow-up-lead';
    }

    public function name(): string
    {
        return 'Follow up on a lead';
    }

    public function script(Persona $persona, Generator $faker): Script
    {
        $who = $persona->firstName();
        $type = $faker->randomElement(['call', 'email', 'meeting', 'text']);
        $task = $faker->randomElement(['Check in with {lead}', 'Send pricing to {lead}', 'Book a demo with {lead}', 'Call {lead} back']);

        $script = $this->signIn(new Script, $persona)
            ->visit('/leads?view=uncontacted', "{$who} opens the leads that need a first touch")
            ->click('tbody tr:first-child a:has-text("Open")', "{$who} opens the first one in the queue")
            ->remember('h1', 'lead', "{$who} reads who it is");

        $script = $this->logActivity($script, $who, $type, ucfirst($type).' with {lead}: '.$faker->sentence(8));

        return $script
            ->fill('input[wire\:model="newTaskTitle"]', $task, "{$who} adds a follow-up: {$task}")
            ->fill('input[wire\:model="newTaskDueAt"]', now()->addDays($faker->numberBetween(1, 10))->setTime(9, 30)->format('Y-m-d\TH:i'), "{$who} picks a day for it")
            ->click('form[wire\:submit\.prevent="addTask"] button[type=submit]', "{$who} adds the follow-up")
            ->see('Task added.', "{$who} sees the follow-up was added")
            ->summary("{$who} followed up with {lead} by {$type}");
    }
}
