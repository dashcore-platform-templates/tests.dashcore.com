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
     * The persona roles (config/simulation.php) that do this work.
     *
     * @return list<string>
     */
    public function roles(): array
    {
        return ['Sales rep'];
    }

    /**
     * Every journey starts by signing in through the app's own login form,
     * whose fields and landing page each target names (`login`).
     */
    protected function signIn(Script $script, Persona $persona): Script
    {
        return self::signInSteps($script, $persona, (array) config("simulation.targets.{$this->target()}.login", []));
    }

    /**
     * The sign-in steps themselves, through a login form whose fields and
     * landing page `$login` may override — shared with customer journeys,
     * whose apps name their own.
     *
     * @param  array{email?: string, password?: string, submit?: string, landed?: string}  $login
     */
    public static function signInSteps(Script $script, Persona $persona, array $login = []): Script
    {
        $who = $persona->firstName();
        $login = [
            'email' => '#email',
            'password' => '#password',
            'submit' => 'form[wire\:submit="login"] button[type=submit]',
            'landed' => 'Dashboard',
            ...$login,
        ];

        return $script
            ->visit('/login', "{$who} opens the login page")
            ->fill($login['email'], $persona->email, "{$who} enters their email")
            ->fill($login['password'], $persona->password, "{$who} enters their password")
            ->click($login['submit'], "{$who} signs in")
            ->see($login['landed'], "{$who} lands on the dashboard");
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
