<?php

namespace App\Simulation;

use App\Enums\SurfaceKind;
use App\Enums\SurfaceStatus;
use App\Models\FleetApp;
use App\Models\SimulationReach;
use App\Models\SimulationRun;
use App\Models\Surface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Which of an app's surfaces real journeys reach. The runner reports what a
 * step reached in the browser's terms; this keeps a running tally per app
 * and maps it onto the routes, components and actions the latest scan of
 * that app listed — so a surface can be proven by a test, used live, both,
 * or neither.
 */
class LiveCoverage
{
    private const KINDS = [SurfaceKind::Route, SurfaceKind::Component, SurfaceKind::Action];

    /**
     * Add a finished run's reach to its app's tally.
     */
    public function record(SimulationRun $run): void
    {
        $reached = $run->reached ?? [];

        DB::transaction(function () use ($run, $reached) {
            foreach ([SimulationReach::PAGE => 'pages', SimulationReach::CALL => 'calls', SimulationReach::MOUNT => 'mounts'] as $kind => $key) {
                foreach (array_unique($reached[$key] ?? []) as $name) {
                    $row = SimulationReach::query()->lockForUpdate()->firstOrNew([
                        'target' => $run->target,
                        'kind' => $kind,
                        'name' => $name,
                    ], ['first_reached_at' => now()]);

                    $row->hits++;
                    $row->last_run_id = $run->id;
                    $row->last_reached_at = now();
                    $row->save();
                }
            }
        });
    }

    /**
     * Fold one step's report into what the run has reached so far, in the
     * forms the tally keeps: page paths with their ids generalised, and only
     * the app's own Livewire calls (not Livewire's $commit and friends).
     *
     * @param  array{pages?: list<string>, calls?: list<string>, mounts?: list<string>}  $step
     * @param  array{pages?: list<string>, calls?: list<string>, mounts?: list<string>}  $so_far
     * @return array{pages: list<string>, calls: list<string>, mounts: list<string>}
     */
    public static function merge(array $so_far, array $step): array
    {
        $pages = array_map(fn (string $page) => preg_replace('#/\d+(?=/|$)#', '/{n}', $page), $step['pages'] ?? []);
        $calls = array_filter($step['calls'] ?? [], fn (string $call) => ! str_contains($call, '@$'));

        return [
            'pages' => array_values(array_unique([...$so_far['pages'] ?? [], ...$pages])),
            'calls' => array_values(array_unique([...$so_far['calls'] ?? [], ...$calls])),
            'mounts' => array_values(array_unique([...$so_far['mounts'] ?? [], ...$step['mounts'] ?? []])),
        ];
    }

    /**
     * The fleet app a simulation target is, by key.
     */
    public function app(string $target): ?FleetApp
    {
        return FleetApp::query()->where('key', config("simulation.targets.{$target}.fleet_app", $target))->first();
    }

    /**
     * The ids of an app's surfaces any simulation target reaches.
     *
     * @return list<int>
     */
    public function reachedSurfaceIds(FleetApp $app): array
    {
        return collect(array_keys(config('simulation.targets', [])))
            ->filter(fn (string $target) => config("simulation.targets.{$target}.fleet_app", $target) === $app->key)
            ->flatMap(fn (string $target) => $this->forTarget($target)['reachedIds'])
            ->unique()
            ->values()
            ->all();
    }

    /**
     * An app's surfaces against what journeys have reached.
     *
     * @return array{
     *     app: ?FleetApp,
     *     total: int,
     *     reached: int,
     *     kinds: array<string, array{label: string, total: int, reached: int}>,
     *     unproven: Collection<int, Surface>,
     *     reachedIds: list<int>,
     *     unmapped: list<string>,
     * }
     */
    public function forTarget(string $target): array
    {
        $app = $this->app($target);
        $reach = SimulationReach::query()->where('target', $target)->get();

        $empty = ['app' => $app, 'total' => 0, 'reached' => 0, 'kinds' => [], 'unproven' => collect(), 'reachedIds' => [], 'unmapped' => []];

        if ($app === null) {
            return [...$empty, 'unmapped' => $reach->where('kind', '!=', SimulationReach::PAGE)->pluck('name')->sort()->values()->all()];
        }

        $surfaces = $app->surfaces()->current()->whereIn('kind', self::KINDS)->get();
        $pages = $reach->where('kind', SimulationReach::PAGE)->pluck('name');
        $names = $this->livewireNames($reach);

        $hit = $surfaces->filter(fn (Surface $surface) => match ($surface->kind) {
            SurfaceKind::Route => $this->routeReached($surface->identifier, $pages),
            default => isset($names[$surface->label]),
        });

        $mapped = $hit->whereIn('kind', [SurfaceKind::Component, SurfaceKind::Action])->pluck('label')->flip();
        $unmapped = $reach->where('kind', SimulationReach::CALL)->pluck('name')
            ->reject(fn (string $name) => $mapped->has($this->canonical($name)))
            ->sort()->values()->all();

        $kinds = [];
        foreach (self::KINDS as $kind) {
            $kinds[$kind->value] = [
                'label' => $kind->label(),
                'total' => $surfaces->where('kind', $kind)->count(),
                'reached' => $hit->where('kind', $kind)->count(),
            ];
        }

        return [
            'app' => $app,
            'total' => $surfaces->count(),
            'reached' => $hit->count(),
            'kinds' => $kinds,
            'unproven' => $hit->whereIn('status', [SurfaceStatus::Missing, SurfaceStatus::Weak])
                ->sortBy(fn (Surface $surface) => [$surface->status === SurfaceStatus::Missing ? 0 : 1, $surface->label])
                ->values(),
            'reachedIds' => $hit->pluck('id')->values()->all(),
            'unmapped' => $unmapped,
        ];
    }

    /**
     * Components and actions reached, by the names the scan labels them with.
     *
     * @param  Collection<int, SimulationReach>  $reach
     * @return array<string, true>
     */
    private function livewireNames(Collection $reach): array
    {
        $names = [];

        foreach ($reach->whereIn('kind', [SimulationReach::CALL, SimulationReach::MOUNT]) as $row) {
            $names[$this->canonical($row->name)] = true;

            // A call proves its component was on screen too.
            if ($row->kind === SimulationReach::CALL) {
                $names[$this->canonical(strtok($row->name, '@'))] = true;
            }
        }

        return $names;
    }

    /**
     * Livewire names an Index component "leads.index" at runtime; the scan
     * calls it "leads", as routes and Livewire's own resolver do.
     */
    private function canonical(string $name): string
    {
        return preg_replace('/\.index(?=@|$)/', '', $name);
    }

    /**
     * @param  Collection<int, string>  $pages  "GET /leads/{n}"
     */
    private function routeReached(string $identifier, Collection $pages): bool
    {
        [$method, $uri] = array_pad(explode(' ', $identifier, 2), 2, '');

        if (! in_array($method, ['GET', 'HEAD'], true)) {
            return false;
        }

        $pattern = preg_quote(trim($uri, '/'), '#');
        $pattern = preg_replace('#/\\\\\{[^/]+\\\\\?\\\\\}#', '(?:/[^/]+)?', $pattern);
        $pattern = preg_replace('#\\\\\{[^/]+\\\\\}#', '[^/]+', $pattern);
        $regex = '#^'.$pattern.'$#';

        return $pages->contains(function (string $page) use ($regex) {
            [$pageMethod, $path] = array_pad(explode(' ', $page, 2), 2, '');

            return $pageMethod === 'GET' && preg_match($regex, trim($path, '/')) === 1;
        });
    }
}
