<?php

namespace App\Simulation\Journeys\Finance;

use App\Simulation\Persona;
use App\Simulation\Script;
use Faker\Generator;

/**
 * A new retainer client: their monthly payment goes into the forecast.
 */
class AddRecurringIncome extends FinanceJourney
{
    public function key(): string
    {
        return 'finance.add-recurring-income';
    }

    public function name(): string
    {
        return 'Add a retainer to the forecast';
    }

    public function script(Persona $persona, Generator $faker): Script
    {
        $who = $persona->firstName();
        $payer = $faker->company().' retainer';
        $amount = (string) ($faker->numberBetween(10, 120) * 100);
        $interval = $faker->randomElement(['monthly', 'monthly', 'biweekly', 'weekly']);

        return $this->signIn(new Script, $persona)
            ->visit('/recurring', "{$who} opens recurring income")
            ->click('button[wire\:click="createEntry"]', "{$who} adds a recurring payer")
            ->fill('input[wire\:model="payerName"]', $payer, "{$who} names it {$payer}")
            ->fill('input[wire\:model="averageAmount"]', $amount, "{$who} enters $".number_format((int) $amount).' a time')
            ->select('select[wire\:model="interval"]', $interval, "{$who} sets it to {$interval}")
            ->fill('input[wire\:model="nextExpectedDate"]', now()->addDays($faker->numberBetween(3, 30))->format('Y-m-d'), "{$who} sets when the next one is due")
            ->fill('textarea[wire\:model="notes"]', $faker->sentence(8), "{$who} adds a note")
            ->click('form[wire\:submit="save"] button[type=submit]', "{$who} saves it")
            ->see('Recurring income entry added.', "{$who} sees it was added")
            ->summary("{$who} added {$payer}: $".number_format((int) $amount)." {$interval}");
    }
}
