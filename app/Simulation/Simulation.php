<?php

namespace App\Simulation;

use App\Enums\JourneyStatus;
use App\Enums\SimulationStatus;
use App\Models\Setting;
use App\Models\SimulationJourney;
use App\Models\SimulationRun;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * The Proving Ground's state: whether it is on, whether a worker is alive to
 * act on that, and what it runs next. The page only reads and flips this; the
 * worker (`tests:simulate`) does the running.
 */
class Simulation
{
    private const ON = 'simulation.on';

    private const ON_SINCE = 'simulation.on_since';

    private const HEARTBEAT = 'simulation.heartbeat';

    private const CONCURRENCY = 'simulation.concurrency';

    /** The most people the page lets run at once on one machine. */
    public const MAX_CONCURRENCY = 6;

    // A worker beats at least every few seconds while idle and between steps;
    // one silent for longer than this is gone, or stuck.
    private const HEARTBEAT_GRACE_SECONDS = 45;

    public function isOn(): bool
    {
        return (bool) Setting::get(self::ON, false);
    }

    public function turnOn(): void
    {
        if (! $this->isOn()) {
            Setting::set(self::ON_SINCE, now()->toIso8601String());
        }

        Setting::set(self::ON, true);
    }

    public function turnOff(): void
    {
        Setting::set(self::ON, false);
    }

    public function onSince(): ?Carbon
    {
        $since = Setting::get(self::ON_SINCE);

        return $this->isOn() && $since ? Carbon::parse($since) : null;
    }

    /**
     * How many journeys run at once: people working side by side.
     */
    public function concurrency(): int
    {
        return max(1, min(self::MAX_CONCURRENCY, (int) Setting::get(self::CONCURRENCY, config('simulation.concurrency', 1))));
    }

    public function setConcurrency(int $people): void
    {
        Setting::set(self::CONCURRENCY, max(1, min(self::MAX_CONCURRENCY, $people)));
    }

    public function heartbeat(): void
    {
        Setting::set(self::HEARTBEAT, now()->toIso8601String());
    }

    public function workerSeenAt(): ?Carbon
    {
        $seen = Setting::get(self::HEARTBEAT);

        return $seen ? Carbon::parse($seen) : null;
    }

    public function workerAlive(): bool
    {
        return $this->workerSeenAt()?->gt(now()->subSeconds(self::HEARTBEAT_GRACE_SECONDS)) ?? false;
    }

    /**
     * Every journey in rotation — written in code, or drafted and on trial or
     * active — by key, with its weight.
     *
     * @return array<string, array{journey: Journey, weight: int}>
     */
    public function journeys(): array
    {
        $journeys = [];

        foreach (config('simulation.journeys', []) as $class => $weight) {
            $journey = app($class);
            $journeys[$journey->key()] = ['journey' => $journey, 'weight' => max(0, (int) $weight)];
        }

        // Drafted journeys: on trial they run often, to prove themselves
        // quickly; once active, at the weight the explorer proposed.
        SimulationJourney::query()
            ->whereIn('status', [JourneyStatus::Trial, JourneyStatus::Active])
            ->whereIn('target', array_keys(config('simulation.targets', [])))
            ->orderBy('id')
            ->each(function (SimulationJourney $record) use (&$journeys) {
                $journeys[$record->key] ??= [
                    'journey' => new StoredJourney($record),
                    'weight' => $record->status === JourneyStatus::Trial
                        ? max(0, (int) config('simulation.trials.weight'))
                        : max(0, min(5, $record->weight)),
                ];
            });

        return $journeys;
    }

    /**
     * The next journey, drawn by weight, and a persona to walk it.
     *
     * @return array{0: Journey, 1: Persona}
     */
    public function pick(?string $journeyKey = null): array
    {
        $journeys = $this->journeys();

        if ($journeyKey !== null) {
            $journey = $journeys[$journeyKey]['journey']
                ?? throw new InvalidArgumentException("No journey \"{$journeyKey}\". Known: ".implode(', ', array_keys($journeys)));
        } else {
            $journey = $this->draw($journeys);
        }

        return [$journey, $this->persona($journey->target(), $journey->roles())];
    }

    /**
     * @param  list<string>|null  $roles  only personas in one of these roles
     */
    public function persona(string $target, ?array $roles = null): Persona
    {
        $config = config("simulation.targets.{$target}")
            ?? throw new InvalidArgumentException("No simulation target \"{$target}\".");

        $personas = array_values(array_filter(
            $config['personas'] ?? [],
            fn (array $persona) => $roles === null || in_array($persona['role'] ?? null, $roles, true),
        ));

        if ($personas === []) {
            throw new InvalidArgumentException("Simulation target \"{$target}\" has no personas".($roles ? ' in the role '.implode(' or ', $roles) : '').'.');
        }

        $persona = $personas[random_int(0, count($personas) - 1)];

        return new Persona($persona['name'], $persona['email'], (string) $config['password'], $persona['role'] ?? 'User');
    }

    /**
     * Each target's vital signs, read from its own database. A target that
     * cannot be read reports why instead of failing the page.
     *
     * @return array<string, array{name: string, metrics: list<array{label: string, value: string, hint: ?string}>, error: ?string}>
     */
    public function pulse(): array
    {
        $since = $this->onSince();

        return Cache::remember('simulation.pulse.'.($since?->timestamp ?? 'day'), 10, function () use ($since) {
            $pulses = [];

            foreach (config('simulation.targets', []) as $key => $target) {
                if (empty($target['pulse']) || empty($target['database'])) {
                    continue;
                }

                try {
                    $timezone = $target['timezone'] ?? config('app.timezone');
                    $metrics = app($target['pulse'])->read(
                        $this->targetDatabase($key, $target['database']),
                        now($timezone),
                        $since?->copy()->setTimezone($timezone),
                    );
                    $pulses[$key] = ['name' => $target['name'] ?? $key, 'metrics' => $metrics, 'error' => null];
                } catch (Throwable $e) {
                    report($e);
                    $pulses[$key] = ['name' => $target['name'] ?? $key, 'metrics' => [], 'error' => Str::limit($e->getMessage(), 300)];
                }
            }

            return $pulses;
        });
    }

    /**
     * How the apps hold up under the simulation: throughput, and step and
     * Livewire latency over the last quarter hour against the first quarter
     * hour after the switch went on — a soak shows up as the gap widening.
     *
     * @return array{in_flight: int, per_hour: int, now: array{p50: ?int, p95: ?int, livewire_p95: ?int}, start: ?array{p50: ?int, p95: ?int, livewire_p95: ?int}}
     */
    public function load(): array
    {
        $since = $this->onSince();
        $window = 15;

        $latency = function (Carbon $from, Carbon $to): array {
            $row = DB::table('simulation_steps')
                ->where('status', SimulationStatus::Passed->value)
                ->whereIn('action', ['visit', 'click'])
                ->whereBetween('updated_at', [$from, $to])
                ->selectRaw('percentile_cont(0.5) within group (order by duration_ms) as p50')
                ->selectRaw('percentile_cont(0.95) within group (order by duration_ms) as p95')
                ->selectRaw('percentile_cont(0.95) within group (order by livewire_ms) filter (where livewire_ms is not null) as livewire_p95')
                ->first();

            return [
                'p50' => $row?->p50 === null ? null : (int) round($row->p50),
                'p95' => $row?->p95 === null ? null : (int) round($row->p95),
                'livewire_p95' => $row?->livewire_p95 === null ? null : (int) round($row->livewire_p95),
            ];
        };

        $start = $since && $since->diffInMinutes(now()) >= 2 * $window
            ? $latency($since, $since->copy()->addMinutes($window))
            : null;

        return [
            'in_flight' => SimulationRun::query()->where('status', SimulationStatus::Running)->count(),
            'per_hour' => SimulationRun::query()->whereNot('status', SimulationStatus::Running)->where('finished_at', '>=', now()->subHour())->count(),
            'now' => $latency(now()->subMinutes($window), now()),
            'start' => $start,
        ];
    }

    /**
     * A connection to a target's database on this app's own server.
     */
    public function targetDatabase(string $key, string $database): ConnectionInterface
    {
        $name = "simulation_{$key}";

        if (config("database.connections.{$name}") === null) {
            config(["database.connections.{$name}" => [
                ...config('database.connections.'.config('database.default')),
                'database' => $database,
            ]]);
        }

        return DB::connection($name);
    }

    public function screenshotDirectory(SimulationRun $run): string
    {
        return rtrim((string) config('simulation.screenshots'), '/').'/'.$run->id;
    }

    /**
     * Runs a dead worker left marked as running can never finish.
     */
    public function recoverInterrupted(): int
    {
        return SimulationRun::query()->where('status', SimulationStatus::Running)->update([
            'status' => SimulationStatus::Stopped,
            'error' => 'The worker stopped before this run finished.',
            'finished_at' => now(),
        ]);
    }

    /**
     * Keep pictures for the newest runs only, and records for keep_days.
     */
    public function prune(): void
    {
        $keep = max(1, (int) config('simulation.keep_runs'));
        $cutoff = SimulationRun::query()->orderByDesc('id')->skip($keep)->value('id');

        if ($cutoff !== null) {
            SimulationRun::query()
                ->where('id', '<=', $cutoff)
                ->where('screenshots_pruned', false)
                ->each(function (SimulationRun $run) {
                    File::deleteDirectory($this->screenshotDirectory($run));
                    $run->update(['screenshots_pruned' => true]);
                });
        }

        SimulationRun::query()
            ->where('created_at', '<', now()->subDays(max(1, (int) config('simulation.keep_days'))))
            ->each(function (SimulationRun $run) {
                File::deleteDirectory($this->screenshotDirectory($run));
                $run->delete();
            });
    }

    /**
     * @param  array<string, array{journey: Journey, weight: int}>  $journeys
     */
    private function draw(array $journeys): Journey
    {
        $total = array_sum(array_column($journeys, 'weight'));

        if ($total === 0) {
            throw new InvalidArgumentException('No journey has a weight above zero in config/simulation.php.');
        }

        $roll = random_int(1, $total);

        foreach ($journeys as $entry) {
            $roll -= $entry['weight'];

            if ($roll <= 0) {
                return $entry['journey'];
            }
        }

        return array_values($journeys)[0]['journey'];
    }
}
