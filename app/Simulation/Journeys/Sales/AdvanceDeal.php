<?php

namespace App\Simulation\Journeys\Sales;

use App\Simulation\Persona;
use App\Simulation\Script;
use Faker\Generator;

/**
 * A deal in the pipeline moves a stage forward, with a fresh probability and
 * a note on why.
 */
class AdvanceDeal extends SalesJourney
{
    /** Each open stage, the one after it, and the probability that goes with it. */
    private const NEXT = [
        'prospecting' => ['qualified', 'Qualified', 25],
        'qualified' => ['proposal', 'Proposal', 50],
        'proposal' => ['negotiation', 'Negotiation', 75],
    ];

    public function key(): string
    {
        return 'sales.advance-deal';
    }

    public function name(): string
    {
        return 'Move a deal forward';
    }

    public function script(Persona $persona, Generator $faker): Script
    {
        $who = $persona->firstName();
        $from = $faker->randomElement(array_keys(self::NEXT));
        [$to, $toLabel, $probability] = self::NEXT[$from];
        $probability += $faker->numberBetween(-5, 10);

        return $this->signIn(new Script, $persona)
            ->visit("/deals?stageFilter={$from}", "{$who} opens the deals in ".ucfirst($from))
            ->remember('tbody tr:first-child td:first-child button', 'deal', "{$who} picks the oldest-looking one")
            ->click('tbody tr:first-child button:has-text("Edit")', "{$who} opens {deal}")
            ->select('#editStage', $to, "{$who} moves it to {$toLabel}")
            ->fill('#editProbability', (string) $probability, "{$who} sets the odds to {$probability}%")
            ->fill('#editNotes', $faker->sentence(14), "{$who} notes why")
            ->click('form[wire\:submit\.prevent="saveDeal"] button[type=submit]', "{$who} saves the deal")
            ->see('Deal saved.', "{$who} sees the deal was saved")
            ->summary("{$who} moved {deal} to {$toLabel}");
    }
}
