<?php

namespace App\Simulation\CustomerJourneys;

use Faker\Generator;
use Illuminate\Support\Str;

/**
 * The fake person a customer journey follows: who they say they are on the
 * website's form, and so who every later app must recognise.
 *
 * New every run, on example.com, so a run never finds an earlier run's
 * records and nothing it types can reach a real inbox.
 */
final readonly class Prospect
{
    public function __construct(
        public string $first,
        public string $last,
        public string $email,
        public string $company,
    ) {}

    public static function fake(Generator $faker): self
    {
        $last = $faker->lastName();

        return new self(
            $faker->firstName(),
            $last,
            'journey+'.Str::lower(Str::random(12)).'@example.com',
            "{$last} Workshop",
        );
    }

    public function name(): string
    {
        return "{$this->first} {$this->last}";
    }
}
