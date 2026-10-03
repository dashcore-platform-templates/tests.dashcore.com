<?php

namespace App\Simulation\Exploring;

use App\Models\SimulationJourney;
use App\Simulation\Simulation;
use App\Simulation\StoredJourney;
use Illuminate\Support\Str;

/**
 * Checks a drafted journey before it may go on trial. The explorer is not
 * trusted: a draft that would leave the app, use a step the browser does not
 * know, reach for a name nothing remembered, or be walked by a role nobody
 * has is turned away with the reason.
 */
class DraftValidator
{
    /** What each step kind needs besides its label. */
    private const NEEDS = [
        'visit' => ['path'],
        'fill' => ['target', 'value'],
        'select' => ['target'],
        'click' => ['target'],
        'see' => ['text'],
        'gone' => ['target'],
        'remember' => ['target', 'as'],
    ];

    private const MIN_STEPS = 2;

    private const MAX_STEPS = 40;

    public function __construct(private Simulation $simulation) {}

    /**
     * @param  array<string, mixed>  $draft
     * @return list<string> what is wrong with it; empty when it may go on trial
     */
    public function problems(array $draft, string $target): array
    {
        $problems = [];
        $key = (string) ($draft['key'] ?? '');

        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $key)) {
            $problems[] = "The key \"{$key}\" is not a kebab-case slug.";
        } elseif (array_key_exists("{$target}.{$key}", $this->simulation->journeys()) || SimulationJourney::query()->where('key', "{$target}.{$key}")->exists()) {
            $problems[] = "A journey called {$target}.{$key} already exists.";
        }

        if (trim((string) ($draft['name'] ?? '')) === '') {
            $problems[] = 'It has no name.';
        }

        $roles = array_column(config("simulation.targets.{$target}.personas", []), 'role');
        foreach ((array) ($draft['roles'] ?? []) as $role) {
            if (! in_array($role, $roles, true)) {
                $problems[] = "Nobody has the role \"{$role}\".";
            }
        }
        if (empty($draft['roles'])) {
            $problems[] = 'It names no role to walk it.';
        }

        $steps = (array) ($draft['steps'] ?? []);
        if (count($steps) < self::MIN_STEPS || count($steps) > self::MAX_STEPS) {
            $problems[] = 'It has '.count($steps).' steps; a journey takes '.self::MIN_STEPS.' to '.self::MAX_STEPS.'.';
        }

        $remembered = [];

        foreach (array_values($steps) as $i => $step) {
            $at = 'Step '.($i + 1);
            $do = $step['do'] ?? null;

            if (! is_array($step) || ! isset(self::NEEDS[$do])) {
                $problems[] = "{$at} has an unknown kind \"".(is_array($step) ? (string) $do : '?').'".';

                continue;
            }

            foreach (['label', ...self::NEEDS[$do]] as $field) {
                if (! isset($step[$field]) || ! is_scalar($step[$field]) || trim((string) $step[$field]) === '') {
                    $problems[] = "{$at} ({$do}) has no {$field}.";
                }
            }

            if ($do === 'select' && ! isset($step['value']) && ! isset($step['option'])) {
                $problems[] = "{$at} (select) says neither which value nor which option.";
            }

            if ($do === 'visit' && ! Str::startsWith((string) ($step['path'] ?? ''), '/')) {
                $problems[] = "{$at} visits \"{$step['path']}\": journeys stay in the app, so a path starts with /.";
            }

            foreach ($this->strings($step) as $text) {
                preg_match_all('/\{([a-z_]+)\}/', $text, $used);
                foreach ($used[1] as $name) {
                    if (! in_array($name, $remembered, true)) {
                        $problems[] = "{$at} uses {{$name}} before any step remembers it.";
                    }
                }

                preg_match_all('/\[\[([a-z]+)(?:\+\d+)?\]\]/', $text, $tokens);
                foreach ($tokens[1] as $token) {
                    if (! in_array($token, [...StoredJourney::TOKENS, 'date', 'datetime'], true)) {
                        $problems[] = "{$at} uses an unknown token [[{$token}]].";
                    }
                }
            }

            if ($do === 'remember' && isset($step['as'])) {
                if (! preg_match('/^[a-z_]+$/', (string) $step['as'])) {
                    $problems[] = "{$at} remembers into \"{$step['as']}\"; names are lowercase letters and underscores.";
                }
                $remembered[] = (string) $step['as'];
            }
        }

        $last = end($steps);
        if (is_array($last) && ! in_array($last['do'] ?? null, ['see', 'gone'], true)) {
            $problems[] = 'It must end by checking the outcome (see or gone), so a pass means the work landed.';
        }

        return array_values(array_unique($problems));
    }

    /**
     * @param  array<string, mixed>  $step
     * @return list<string>
     */
    private function strings(array $step): array
    {
        return array_values(array_filter($step, 'is_string'));
    }
}
