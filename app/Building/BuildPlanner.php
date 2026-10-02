<?php

namespace App\Building;

use App\Enums\SurfaceChange;
use App\Enums\SurfaceKind;
use App\Enums\SurfaceStatus;
use App\Enums\VerdictReason;
use App\Models\Build;
use App\Models\FleetApp;
use App\Models\ScanSurface;
use App\Models\Surface;
use Illuminate\Support\Collection;

/**
 * Chooses what a build should cover: one coherent batch of open surfaces, so
 * the agent works in one area and the PR reviews as one change.
 *
 * The most urgent open surface leads — a regression first, then something
 * nothing tests, then a method whose tests do not bite — and the rest of the
 * batch comes from the same place: the same class or component file, or for
 * routes the same first path segment. Surfaces already held by a running
 * build or a PR waiting on review are never offered twice.
 */
class BuildPlanner
{
    /**
     * @return Collection<int, Surface>
     */
    public function plan(FleetApp $app, ?Surface $lead = null): Collection
    {
        $scan = $app->latestCompletedScan()->first();

        if ($scan === null) {
            return collect();
        }

        $held = $this->held($app);
        $limit = max(1, (int) config('scanner.build.targets'));

        $open = $app->surfaces()->current()
            ->whereIn('status', [SurfaceStatus::Missing, SurfaceStatus::Weak])
            ->whereNotIn('id', $held)
            ->get();

        if ($lead !== null) {
            if (! $open->contains('id', $lead->id)) {
                return collect();
            }
        } else {
            $regressions = ScanSurface::query()
                ->where('scan_id', $scan->id)
                ->whereIn('change', [SurfaceChange::Lost, SurfaceChange::NewUntested])
                ->pluck('surface_id')
                ->flip();

            $lead = $open->sortBy(fn (Surface $s) => [
                isset($regressions[$s->id]) ? 0 : 1,
                $this->urgency($s),
                $s->id,
            ])->first();
        }

        if ($lead === null) {
            return collect();
        }

        $area = $this->area($lead);

        return $open
            ->filter(fn (Surface $s) => $s->id !== $lead->id && $this->area($s) === $area)
            ->sortBy(fn (Surface $s) => [$this->urgency($s), $s->start_line ?? 0, $s->id])
            ->prepend($lead)
            ->take($limit)
            ->values();
    }

    /**
     * @return list<int>
     */
    public function held(FleetApp $app): array
    {
        return $app->builds()->holdingTargets()->pluck('targets')->flatten()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * Untested before weak; behaviour a user reaches before internals.
     */
    private function urgency(Surface $surface): int
    {
        $base = match (true) {
            $surface->status === SurfaceStatus::Missing => 0,
            $surface->reason === VerdictReason::MutantSurvived => 10,
            default => 20,
        };

        return $base + match ($surface->kind) {
            SurfaceKind::Route, SurfaceKind::Action, SurfaceKind::Policy => 0,
            SurfaceKind::Job, SurfaceKind::Command, SurfaceKind::Controller, SurfaceKind::McpTool, SurfaceKind::Mail, SurfaceKind::Notification => 1,
            SurfaceKind::Component, SurfaceKind::Service, SurfaceKind::Listener, SurfaceKind::Middleware, SurfaceKind::Rule => 2,
            default => 3,
        };
    }

    private function area(Surface $surface): string
    {
        if ($surface->file !== null) {
            return 'file:'.$surface->file;
        }

        $uri = explode(' ', $surface->identifier, 2)[1] ?? $surface->identifier;

        return 'route:'.explode('/', trim($uri, '/'))[0];
    }
}
