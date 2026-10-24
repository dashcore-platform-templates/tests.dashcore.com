<?php

namespace App\Simulation;

use App\Models\Launch;
use Illuminate\Support\Str;

/**
 * A launch (`tests:launch`), read as the client journey map draws it: every
 * stage of bringing a client on in order — flown or not — and inside each,
 * the hops from app to app with what carried the client across (an email,
 * a card payment, work done outside the browser), step by step.
 *
 * Drawn only from what the launch recorded, so the map is exactly what the
 * apps did on the night, never a diagram someone keeps up by hand.
 */
class JourneyMap
{
    /** Each app's colour on the map, by the key the journeys drive it under. */
    private const COLOURS = [
        'website' => '#0ea5e9',
        'sales' => '#8b5cf6',
        'delivery' => '#f59e0b',
        'cockpit' => '#10b981',
        'login' => '#ec4899',
        'onboarding' => '#6366f1',
    ];

    /**
     * The stages a launch flies, in order, each with its record when it was
     * flown: passed, failed, or never reached.
     *
     * @return list<array{key: string, name: string, journey: ?string, status: string, seconds: ?float, walks: list<array<string, mixed>>, said: list<string>, errors: list<string>, apps: list<string>}>
     */
    public function stages(?Launch $launch): array
    {
        $flown = collect($launch?->stages ?? [])->keyBy('stage');
        $keys = array_values(array_unique([...(array) config('simulation.launch'), ...$flown->keys()->all()]));

        return array_map(function (string $key) use ($flown): array {
            $stage = (array) config("simulation.customer_journeys.{$key}", []);
            $record = $flown->get($key);

            return [
                'key' => $key,
                'name' => (string) ($record['name'] ?? $stage['name'] ?? Str::headline($key)),
                'journey' => $record['journey'] ?? (empty($stage['journey']) ? null : app($stage['journey'])->name()),
                'status' => $record === null ? 'not-flown' : ($record['passed'] ? 'passed' : 'failed'),
                'seconds' => $record['seconds'] ?? null,
                'walks' => array_map(fn (array $walk): array => [...$walk, 'hops' => $this->hops($walk, (array) ($record['apps'] ?? []))], (array) ($record['walks'] ?? [])),
                'said' => array_values((array) ($record['said'] ?? [])),
                'errors' => array_values((array) ($record['errors'] ?? [])),
                'apps' => $this->appsOf((array) ($record['walks'] ?? [])),
            ];
        }, $keys);
    }

    /**
     * A walk's steps cut where the client moves from one app to the next,
     * each hop saying how they got there.
     *
     * @param  array<string, mixed>  $walk
     * @param  array<string, array{name: string, base_url: string}>  $apps
     * @return list<array{app: ?string, name: string, host: ?string, colour: string, via: ?string, steps: list<array<string, mixed>>}>
     */
    public function hops(array $walk, array $apps = []): array
    {
        $hops = [];

        foreach ((array) ($walk['steps'] ?? []) as $step) {
            $app = $step['on'] ?? null;

            if ($hops === [] || end($hops)['app'] !== $app) {
                $hops[] = [
                    'app' => $app,
                    'name' => $app === null ? 'Browser' : (string) ($apps[$app]['name'] ?? Str::headline($app)),
                    'host' => isset($apps[$app]['base_url']) ? parse_url((string) $apps[$app]['base_url'], PHP_URL_HOST) : null,
                    'colour' => $this->colour($app),
                    'via' => $hops === [] ? null : $this->via($step),
                    'steps' => [],
                ];
            }

            $hops[array_key_last($hops)]['steps'][] = $step;
        }

        return $hops;
    }

    /** How the client crossed into the next app, read off its first step there. */
    public function via(array $step): string
    {
        return match ($step['do'] ?? null) {
            'mail' => 'by email',
            'stripe_checkout' => 'by card, on Stripe',
            default => 'in the browser',
        };
    }

    public function colour(?string $app): string
    {
        return self::COLOURS[$app] ?? '#'.substr(md5((string) $app), 0, 6);
    }

    /**
     * The apps a stage touched, in the order it reached them.
     *
     * @param  list<array<string, mixed>>  $walks
     * @return list<string>
     */
    private function appsOf(array $walks): array
    {
        return collect($walks)
            ->flatMap(fn (array $walk) => array_column((array) ($walk['steps'] ?? []), 'on'))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
