<?php

namespace App\Simulation\Journeys\Finance;

use App\Simulation\Persona;
use App\Simulation\Script;
use Faker\Generator;

/**
 * A client's payment that came in outside QuickBooks gets entered by hand.
 */
class RecordIncome extends FinanceJourney
{
    public function key(): string
    {
        return 'finance.record-income';
    }

    public function name(): string
    {
        return 'Record income';
    }

    public function script(Persona $persona, Generator $faker): Script
    {
        $who = $persona->firstName();
        $amount = number_format($faker->numberBetween(50, 900) * 50, 2, '.', '');
        $client = $faker->company();
        $description = "Payment from {$client}";

        $script = $this->addTransaction($this->signIn(new Script, $persona), $who, 'income', $amount, now()->subDays($faker->numberBetween(0, 10))->format('Y-m-d'), $description);

        return $this->findTransaction($script, $who, $description)
            ->summary("{$who} recorded $".number_format((float) $amount, 2)." from {$client}");
    }
}
