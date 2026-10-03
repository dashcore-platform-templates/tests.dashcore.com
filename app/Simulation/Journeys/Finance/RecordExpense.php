<?php

namespace App\Simulation\Journeys\Finance;

use App\Simulation\Persona;
use App\Simulation\Script;
use Faker\Generator;

/**
 * A bill paid outside QuickBooks gets entered by hand.
 */
class RecordExpense extends FinanceJourney
{
    public function key(): string
    {
        return 'finance.record-expense';
    }

    public function name(): string
    {
        return 'Record an expense';
    }

    public function script(Persona $persona, Generator $faker): Script
    {
        $who = $persona->firstName();
        $amount = number_format($faker->numberBetween(1500, 250000) / 100, 2, '.', '');
        $vendor = $faker->company();
        $what = $faker->randomElement(['Software subscription', 'Office supplies', 'Contractor invoice', 'Travel', 'Hosting', 'Client lunch']);
        $description = "{$what} — {$vendor}";

        $script = $this->addTransaction($this->signIn(new Script, $persona), $who, 'expense', $amount, now()->subDays($faker->numberBetween(0, 20))->format('Y-m-d'), $description);

        return $this->findTransaction($script, $who, $description)
            ->summary("{$who} recorded a $".number_format((float) $amount, 2)." expense: {$description}");
    }
}
