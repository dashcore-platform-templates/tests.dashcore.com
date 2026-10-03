<?php

namespace App\Simulation\Journeys\Finance;

use App\Simulation\Persona;
use App\Simulation\Script;
use Faker\Generator;

/**
 * The finance lead adds a category for a new kind of spending.
 */
class AddCategory extends FinanceJourney
{
    public function key(): string
    {
        return 'finance.add-category';
    }

    public function name(): string
    {
        return 'Add a category';
    }

    public function roles(): array
    {
        return ['Finance lead'];
    }

    public function script(Persona $persona, Generator $faker): Script
    {
        $who = $persona->firstName();
        $name = ucfirst($faker->word()).' '.$faker->randomElement(['tooling', 'services', 'events', 'licences', 'equipment']).' '.$faker->bothify('##');

        return $this->signIn(new Script, $persona)
            ->visit('/categories', "{$who} opens the categories")
            ->click('button[wire\:click="createCategory"]', "{$who} starts a new category")
            ->fill('form[wire\:submit="save"] input[wire\:model="name"]', $name, "{$who} names it {$name}")
            ->select('form[wire\:submit="save"] select[wire\:model="type"]', 'expense', "{$who} makes it an expense category")
            ->click('form[wire\:submit="save"] button[type=submit]', "{$who} saves it")
            ->see('Category added.', "{$who} sees it was added")
            ->summary("{$who} added the expense category {$name}");
    }
}
