<?php

namespace App\Workflows;

use App\Simulation\StoredJourney;
use Faker\Generator;
use Illuminate\Support\Str;

/**
 * The steps a workflow can take — a plain subset of the browser runner's
 * own step kinds (simulator/run.mjs) — and how a stored list becomes the
 * script one run walks.
 *
 * Values may hold fake-data tokens, filled fresh for every run (the same
 * token twice in a run is the same value):
 *   [[first]] [[last]] [[name]] [[email]] [[phone]] [[company]]
 *   [[website]] [[sentence]]   [[date+N]] / [[datetime+N]]
 */
class Steps
{
    /**
     * Each kind: what the builder calls it, and the field it needs beyond
     * its label ('path', 'target', 'text', 'key'), plus 'value' for those
     * that type or pick something.
     *
     * @var array<string, array{name: string, field: string, value: bool, hint: string}>
     */
    public const KINDS = [
        'visit' => ['name' => 'Open a page', 'field' => 'path', 'value' => false, 'hint' => 'A path like /lets-talk, or a full URL'],
        'fill' => ['name' => 'Type into a field', 'field' => 'target', 'value' => true, 'hint' => 'CSS selector, e.g. #email'],
        'select' => ['name' => 'Pick an option', 'field' => 'target', 'value' => true, 'hint' => 'CSS selector of the <select>'],
        'click' => ['name' => 'Click', 'field' => 'target', 'value' => false, 'hint' => 'CSS selector, e.g. button[type=submit]'],
        'see' => ['name' => 'See text', 'field' => 'text', 'value' => false, 'hint' => 'Text that must appear on the page'],
        'gone' => ['name' => 'Wait until gone', 'field' => 'target', 'value' => false, 'hint' => 'CSS selector that must leave the page'],
        'filled' => ['name' => 'Wait for a field to fill', 'field' => 'target', 'value' => false, 'hint' => 'e.g. a captcha widget\'s hidden token input'],
        'press' => ['name' => 'Press a key', 'field' => 'key', 'value' => false, 'hint' => 'e.g. Enter'],
    ];

    public const TOKENS = ['first', 'last', 'name', 'email', 'phone', 'company', 'website', 'sentence'];

    /**
     * Validation rules for a list of steps under `$prefix` (e.g. 'steps').
     *
     * @return array<string, mixed>
     */
    public static function rules(string $prefix = 'steps'): array
    {
        return [
            $prefix => ['required', 'array', 'min:1', 'max:60'],
            "{$prefix}.*.do" => ['required', 'string', 'in:'.implode(',', array_keys(self::KINDS))],
            "{$prefix}.*.label" => ['nullable', 'string', 'max:200'],
            "{$prefix}.*.path" => ['required_if:'.$prefix.'.*.do,visit', 'nullable', 'string', 'max:500'],
            "{$prefix}.*.target" => ['required_if:'.$prefix.'.*.do,fill,select,click,gone,filled', 'nullable', 'string', 'max:500'],
            "{$prefix}.*.text" => ['required_if:'.$prefix.'.*.do,see', 'nullable', 'string', 'max:500'],
            "{$prefix}.*.key" => ['required_if:'.$prefix.'.*.do,press', 'nullable', 'string', 'max:50'],
            "{$prefix}.*.value" => ['required_if:'.$prefix.'.*.do,fill,select', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Keep only what each step's kind uses, so a step switched from "Type"
     * to "Click" does not carry a stale value into the browser.
     *
     * @param  list<array<string, mixed>>  $steps
     * @return list<array<string, string>>
     */
    public static function clean(array $steps): array
    {
        return array_values(array_map(function (array $step) {
            $kind = self::KINDS[$step['do']];
            $clean = ['do' => $step['do'], 'label' => trim((string) ($step['label'] ?? ''))];
            $clean[$kind['field']] = trim((string) ($step[$kind['field']] ?? ''));

            if ($kind['value']) {
                $clean['value'] = (string) ($step['value'] ?? '');
            }

            return $clean;
        }, $steps));
    }

    /**
     * The steps one run walks: tokens filled with this run's fake contact,
     * and a label made up for any step that has none.
     *
     * @param  list<array<string, string>>  $steps
     * @return list<array<string, string>>
     */
    public static function script(array $steps, Generator $faker): array
    {
        $values = self::fakeValues($faker);
        $fill = fn (mixed $text) => is_string($text) ? StoredJourney::fill($text, $values) : $text;

        return array_values(array_map(function (array $step) use ($fill) {
            $step = array_map($fill, $step);
            $step['label'] = ($step['label'] ?? '') !== '' ? $step['label'] : self::describe($step);

            return $step;
        }, $steps));
    }

    /**
     * A plain-words label for a step nobody labelled.
     *
     * @param  array<string, string>  $step
     */
    public static function describe(array $step): string
    {
        $field = self::KINDS[$step['do']]['field'] ?? 'target';

        return Str::limit(match ($step['do']) {
            'visit' => "Open {$step['path']}",
            'fill' => "Type into {$step['target']}",
            'select' => "Pick \"{$step['value']}\" in {$step['target']}",
            'click' => "Click {$step['target']}",
            'see' => "See \"{$step['text']}\"",
            'gone' => "Wait for {$step['target']} to go",
            'filled' => "Wait for {$step['target']} to fill",
            'press' => "Press {$step['key']}",
            default => (string) ($step[$field] ?? $step['do']),
        }, 200);
    }

    /**
     * @return array<string, string>
     */
    private static function fakeValues(Generator $faker): array
    {
        $first = $faker->firstName();
        $last = $faker->lastName();

        return [
            'first' => $first,
            'last' => $last,
            'name' => "{$first} {$last}",
            'email' => str_replace('{random}', Str::lower(Str::random(12)), (string) config('workflows.fake_email')),
            'phone' => $faker->numerify('555-###-####'),
            'company' => "{$last} Workshop",
            'website' => 'https://'.Str::slug($last).'-workshop.example.com',
            'sentence' => $faker->sentence(10),
        ];
    }
}
