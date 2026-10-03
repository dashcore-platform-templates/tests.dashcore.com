<?php

namespace App\Simulation\Journeys\Finance;

use App\Simulation\Persona;
use App\Simulation\Script;
use Faker\Generator;

/**
 * A look around the books: reports, budget against actual, vendors and
 * alerts. Changes nothing; proves every page renders with the ledger the
 * simulation keeps adding to.
 */
class ReviewBooks extends FinanceJourney
{
    public function key(): string
    {
        return 'finance.review-books';
    }

    public function name(): string
    {
        return 'Review the books';
    }

    public function script(Persona $persona, Generator $faker): Script
    {
        $who = $persona->firstName();

        return $this->signIn(new Script, $persona)
            ->visit('/reports', "{$who} opens the reports")
            ->see('Reports', "{$who} sees the reports")
            ->visit('/reports/budget-vs-actual', "{$who} checks budget against actual")
            ->see('Budget', "{$who} sees the budget report")
            ->visit('/vendors', "{$who} looks at the vendors")
            ->see('Vendors', "{$who} sees the vendors")
            ->visit('/alerts', "{$who} checks the alerts")
            ->see('Alerts', "{$who} sees the alerts")
            ->summary("{$who} reviewed the reports, budgets, vendors and alerts");
    }
}
