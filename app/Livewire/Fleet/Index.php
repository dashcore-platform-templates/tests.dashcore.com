<?php

namespace App\Livewire\Fleet;

use App\Enums\BuildStatus;
use App\Models\FleetApp;
use App\Scanning\FleetSync;
use App\Scanning\ScanRequester;
use App\Services\PermissionService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The fleet matrix: every app in this install's fleet, how much of it the
 * suite proves, and where it stands right now.
 */
class Index extends Component
{
    public function scan(int $appId, ScanRequester $requester): void
    {
        $this->authorizeFull();

        $app = FleetApp::findOrFail($appId);
        $scan = $requester->request($app, auth()->user());

        $this->dispatch('fleet-notice', message: $scan === null
            ? ($app->isScannable() ? "{$app->name} is already being scanned." : "{$app->name} has no checkout on this machine.")
            : "Scan of {$app->name} queued.");

        unset($this->apps);
    }

    public function scanAll(ScanRequester $requester): void
    {
        $this->authorizeFull();

        $queued = FleetApp::query()->orderBy('key')->get()
            ->filter(fn (FleetApp $app) => $requester->request($app, auth()->user()) !== null)
            ->count();

        $this->dispatch('fleet-notice', message: $queued === 0 ? 'Nothing to queue — every scannable app is already scanning.' : "{$queued} scan(s) queued.");

        unset($this->apps);
    }

    public function syncFleet(FleetSync $sync): void
    {
        $this->authorizeFull();

        $result = $sync->sync();

        $this->dispatch('fleet-notice', message: $result['error'] ?? sprintf(
            'Fleet synced: %d added, %d updated, %d removed.',
            count($result['added']),
            count($result['updated']),
            count($result['removed']),
        ));

        unset($this->apps);
    }

    /**
     * @return Collection<int, FleetApp>
     */
    #[Computed]
    public function apps(): Collection
    {
        // Worst first: what needs attention leads. Apps not scanned yet follow,
        // by name.
        return FleetApp::query()
            ->with(['latestScan', 'latestCompletedScan'])
            ->withCount(['builds as open_prs_count' => fn ($q) => $q->where('status', BuildStatus::Opened)])
            ->get()
            ->sortBy(fn (FleetApp $app) => [
                $app->latestCompletedScan === null ? 1 : 0,
                $app->latestCompletedScan === null ? 0 : (float) $app->latestCompletedScan->score,
                $app->name,
            ])
            ->values();
    }

    #[Computed]
    public function canManage(): bool
    {
        return app(PermissionService::class)->hasFullAccessForUser(auth()->user(), 'fleet-tests');
    }

    /**
     * Fleet totals, weighted by surface rather than averaged per app, so a
     * 1,000-surface app at 40% counts for more than a 10-surface app at 100%.
     *
     * @return array{apps: int, scanned: int, score: ?float, tested: int, total: int, missing: int, weak: int, failing: int}
     */
    #[Computed]
    public function totals(): array
    {
        $scans = $this->apps->pluck('latestCompletedScan')->filter();
        $total = (int) $scans->sum('surfaces_total');
        $tested = (int) $scans->sum('surfaces_tested');

        return [
            'apps' => $this->apps->count(),
            'scanned' => $scans->count(),
            'score' => $total === 0 ? null : round($tested / $total * 100, 1),
            'tested' => $tested,
            'total' => $total,
            'missing' => (int) $scans->sum('surfaces_missing'),
            'weak' => (int) $scans->sum('surfaces_weak'),
            'failing' => (int) $scans->sum('tests_failed'),
        ];
    }

    #[Computed]
    public function anyActive(): bool
    {
        return $this->apps->contains(fn (FleetApp $app) => $app->latestScan?->status->isActive() ?? false);
    }

    public function render()
    {
        return view('livewire.fleet.index', [
            'lastSync' => FleetSync::lastResult(),
            'scannerRoot' => config('scanner.root'),
        ]);
    }

    private function authorizeFull(): void
    {
        abort_unless($this->canManage, 403);
    }
}
