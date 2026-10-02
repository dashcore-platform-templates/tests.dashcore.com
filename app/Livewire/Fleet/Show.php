<?php

namespace App\Livewire\Fleet;

use App\Enums\SurfaceChange;
use App\Enums\SurfaceKind;
use App\Enums\SurfaceStatus;
use App\Enums\TestOutcome;
use App\Models\FleetApp;
use App\Models\Scan;
use App\Models\ScanSurface;
use App\Scanning\ScanRequester;
use App\Services\PermissionService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * One app: its latest verdict, every surface with the tests that prove it
 * (or the fact that none do), the failing tests, and its scan history.
 */
class Show extends Component
{
    use WithPagination;

    #[Locked]
    public int $appId;

    /** `open` is missing and weak together — the work still to do. */
    #[Url]
    public string $status = 'open';

    #[Url]
    public string $kind = '';

    #[Url(as: 'q')]
    public string $search = '';

    /** `regressions` narrows to what the latest scan flagged against the one before it. */
    #[Url]
    public string $change = '';

    public function showRegressions(): void
    {
        $this->status = 'all';
        $this->kind = '';
        $this->search = '';
        $this->change = 'regressions';
        $this->resetPage();
    }

    public function mount(string $appKey): void
    {
        $this->appId = FleetApp::query()->where('key', $appKey)->firstOrFail()->id;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'kind', 'search', 'change'], true)) {
            $this->resetPage();
        }
    }

    public function scan(ScanRequester $requester): void
    {
        abort_unless($this->canManage, 403);

        $scan = $requester->request($this->app, auth()->user());

        $this->dispatch('fleet-notice', message: $scan === null
            ? ($this->app->isScannable() ? 'A scan is already queued or running.' : 'There is no checkout of this app on this machine.')
            : 'Scan queued.');

        unset($this->app, $this->latestScan);
    }

    #[Computed]
    public function app(): FleetApp
    {
        return FleetApp::with('latestScan')->findOrFail($this->appId);
    }

    #[Computed]
    public function latestScan(): ?Scan
    {
        return $this->app->latestCompletedScan()->first();
    }

    #[Computed]
    public function canManage(): bool
    {
        return app(PermissionService::class)->hasFullAccessForUser(auth()->user(), 'fleet-tests');
    }

    /**
     * Tested, weak and missing counts per kind, for the latest scan.
     *
     * @return Collection<string, array{kind: SurfaceKind, tested: int, weak: int, missing: int, total: int}>
     */
    #[Computed]
    public function breakdown(): Collection
    {
        if ($this->latestScan === null) {
            return collect();
        }

        return $this->app->surfaces()->current()
            ->selectRaw('kind, status, count(*) as total')
            ->groupBy('kind', 'status')
            ->get()
            ->groupBy(fn ($row) => $row->kind->value)
            ->map(function (Collection $rows) {
                $count = fn (SurfaceStatus $s) => (int) $rows->first(fn ($r) => $r->status === $s)?->total;

                return [
                    'kind' => $rows->first()->kind,
                    'tested' => $count(SurfaceStatus::Tested),
                    'weak' => $count(SurfaceStatus::Weak),
                    'missing' => $count(SurfaceStatus::Missing),
                    'total' => (int) $rows->sum('total'),
                ];
            })
            ->sortBy(fn (array $row) => array_search($row['kind'], SurfaceKind::cases(), true));
    }

    #[Computed]
    public function surfaces()
    {
        return $this->app->surfaces()->current()
            ->when($this->status === 'open', fn ($q) => $q->whereIn('status', [SurfaceStatus::Missing, SurfaceStatus::Weak]))
            ->when(SurfaceStatus::tryFrom($this->status), fn ($q, SurfaceStatus $s) => $q->where('status', $s))
            ->when(SurfaceKind::tryFrom($this->kind), fn ($q, SurfaceKind $k) => $q->where('kind', $k))
            ->when($this->change !== '' && $this->latestScan !== null, fn ($q) => $q->whereIn('id', ScanSurface::query()
                ->select('surface_id')
                ->where('scan_id', $this->latestScan->id)
                ->whereIn('change', $this->change === 'regressions'
                    ? [SurfaceChange::NewUntested, SurfaceChange::Lost]
                    : array_filter([SurfaceChange::tryFrom($this->change)]))))
            ->when(trim($this->search) !== '', fn ($q) => $q->where(fn ($q) => $q
                ->whereLike('label', '%'.trim($this->search).'%')
                ->orWhereLike('identifier', '%'.trim($this->search).'%')
                ->orWhereLike('file', '%'.trim($this->search).'%')))
            ->orderByRaw("case status when 'missing' then 0 when 'weak' then 1 else 2 end")
            ->orderBy('kind')
            ->orderBy('label')
            ->paginate(50);
    }

    /**
     * The evidence for the surfaces on this page, from the latest scan.
     *
     * @return Collection<int, ScanSurface>
     */
    #[Computed]
    public function evidence(): Collection
    {
        if ($this->latestScan === null) {
            return collect();
        }

        return ScanSurface::query()
            ->where('scan_id', $this->latestScan->id)
            ->whereIn('surface_id', $this->surfaces->pluck('id'))
            ->get()
            ->keyBy('surface_id');
    }

    #[Computed]
    public function failingTests(): Collection
    {
        return $this->latestScan?->tests()
            ->whereIn('outcome', [TestOutcome::Failed, TestOutcome::Errored])
            ->orderBy('file')
            ->limit(100)
            ->get() ?? collect();
    }

    #[Computed]
    public function history(): Collection
    {
        return $this->app->scans()->latest('id')->limit(15)->get();
    }

    public function render()
    {
        return view('livewire.fleet.show', [
            'kinds' => SurfaceKind::cases(),
        ]);
    }
}
