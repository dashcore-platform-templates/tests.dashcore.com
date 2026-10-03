<?php

namespace App\Simulation;

use App\Enums\SimulationStatus;
use App\Models\Setting;
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
     * Every journey in rotation, by key, with its weight.
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
     * A connection to a target's database on this app's own server.
     */
    private function targetDatabase(string $key, string $database): ConnectionInterface
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
