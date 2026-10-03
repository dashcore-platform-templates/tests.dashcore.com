<?php

namespace App\Simulation;

use Faker\Generator;

/**
 * A piece of real work in one app, walked end to end through its UI.
 */
abstract class Journey
{
    /** Stable identifier, e.g. "sales.capture-lead". */
    abstract public function key(): string;

    /** What the journey is, for people: "Capture a lead". */
    abstract public function name(): string;

    /** The key of the target in config/simulation.php it runs against. */
    abstract public function target(): string;

    abstract public function script(Persona $persona, Generator $faker): Script;

    /**
     * Every journey starts by signing in through the app's own login form.
     */
    protected function signIn(Script $script, Persona $persona): Script
    {
        $who = $persona->firstName();

        return $script
            ->visit('/login', "{$who} opens the login page")
            ->fill('#email', $persona->email, "{$who} enters their email")
            ->fill('#password', $persona->password, "{$who} enters their password")
            ->click('form[wire\:submit="login"] button[type=submit]', "{$who} signs in")
            ->see('Dashboard', "{$who} lands on the dashboard");
    }

    /**
     * A contact no earlier run has used: the address carries a random tag, so
     * runs never trip over each other's unique emails.
     *
     * @return array{name: string, email: string, phone: string, company: string, website: string}
     */
    protected function prospect(Generator $faker): array
    {
        $first = $faker->firstName();
        $last = $faker->lastName();
        $company = $faker->company();

        return [
            'name' => "{$first} {$last}",
            'email' => strtolower("{$first}.{$last}.".$faker->bothify('??##')).'@example.test',
            'phone' => $faker->numerify('(###) 555-####'),
            'company' => $company,
            'website' => str($company)->slug()->limit(30, '')->append('.example')->toString(),
        ];
    }
}
