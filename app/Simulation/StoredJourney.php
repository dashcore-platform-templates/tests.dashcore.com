<?php

namespace App\Simulation;

use App\Models\SimulationJourney;
use Faker\Generator;

/**
 * A journey kept as data — drafted by the explorer — walked like any other.
 *
 * Its steps are the browser's own step kinds. Fake data is written as
 * [[tokens]], filled fresh for every run (the same token twice in one run
 * is the same value, so a name typed in one step can be looked for in the
 * next); {name} is still what an earlier step remembered off the page.
 *
 *   [[who]]  the persona's first name   [[name]] [[first]] [[last]] [[email]]
 *   [[phone]] [[company]] [[website]]   a fresh contact
 *   [[sentence]]  a line of text        [[amount]]  a deal-sized number
 *   [[date+N]] / [[datetime+N]]         N days from today, for date inputs
 */
class StoredJourney extends Journey
{
    public const TOKENS = ['who', 'name', 'first', 'last', 'email', 'phone', 'company', 'website', 'sentence', 'amount'];

    public function __construct(public readonly SimulationJourney $record) {}

    public function key(): string
    {
        return $this->record->key;
    }

    public function name(): string
    {
        return $this->record->name;
    }

    public function target(): string
    {
        return $this->record->target;
    }

    public function roles(): array
    {
        return $this->record->roles;
    }

    public function script(Persona $persona, Generator $faker): Script
    {
        $contact = $this->prospect($faker);
        [$first, $last] = explode(' ', $contact['name'], 2) + [1 => ''];
        $values = [
            'who' => $persona->firstName(),
            'name' => $contact['name'],
            'first' => $first,
            'last' => $last,
            'email' => $contact['email'],
            'phone' => $contact['phone'],
            'company' => $contact['company'],
            'website' => $contact['website'],
            'sentence' => $faker->sentence(10),
            'amount' => (string) ($faker->numberBetween(20, 600) * 100),
        ];

        $fill = fn (mixed $text) => is_string($text) ? self::fill($text, $values) : $text;
        $script = $this->signIn(new Script, $persona);

        foreach ($this->record->steps as $step) {
            $step = array_map($fill, $step);
            $label = (string) ($step['label'] ?? '');

            match ($step['do']) {
                'visit' => $script->visit($step['path'], $label),
                'fill' => $script->fill($step['target'], (string) $step['value'], $label),
                'select' => isset($step['option'])
                    ? $script->choose($step['target'], (string) $step['option'], $label)
                    : $script->select($step['target'], (string) $step['value'], $label),
                'click' => $script->click($step['target'], $label, (bool) ($step['confirm'] ?? false)),
                'see' => $script->see($step['text'], $label),
                'gone' => $script->gone($step['target'], $label),
                'remember' => $script->remember($step['target'], $step['as'], $label),
            };
        }

        if ($this->record->summary) {
            $script->summary($fill($this->record->summary));
        }

        return $script;
    }

    /**
     * @param  array<string, string>  $values
     */
    public static function fill(string $text, array $values): string
    {
        return preg_replace_callback('/\[\[([a-z]+)(?:\+(\d+))?\]\]/', function (array $m) use ($values) {
            return match ($m[1]) {
                'date' => now()->addDays((int) ($m[2] ?? 0))->format('Y-m-d'),
                'datetime' => now()->addDays((int) ($m[2] ?? 0))->setTime(10, 0)->format('Y-m-d\TH:i'),
                default => $values[$m[1]] ?? $m[0],
            };
        }, $text);
    }
}
