<?php

namespace App\Simulation\Journeys\Sales;

use App\Simulation\Persona;
use App\Simulation\Script;
use Faker\Generator;

/**
 * The owner's morning look around: dashboard, today, both boards, the late
 * stage deals and the reports. Changes nothing; proves every page renders
 * with whatever the simulation has piled up.
 */
class ReviewPipeline extends SalesJourney
{
    public function key(): string
    {
        return 'sales.review-pipeline';
    }

    public function name(): string
    {
        return 'Review the pipeline';
    }

    public function roles(): array
    {
        return ['Owner'];
    }

    public function script(Persona $persona, Generator $faker): Script
    {
        $who = $persona->firstName();

        return $this->signIn(new Script, $persona)
            ->visit('/today', "{$who} checks what is due today")
            ->see('Today', "{$who} sees today's list")
            ->visit('/leads/board', "{$who} opens the lead board")
            ->see('Lead board', "{$who} sees the lead board")
            ->visit('/deals/board', "{$who} opens the deal board")
            ->see('Deal board', "{$who} sees the deal board")
            ->visit('/deals?stageFilter=negotiation', "{$who} looks at the deals in negotiation")
            ->see('Deals', "{$who} sees the deals list")
            ->visit('/reports', "{$who} opens the reports")
            ->see('Reports', "{$who} sees the reports")
            ->summary("{$who} reviewed the boards, the late-stage deals and the reports");
    }
}
