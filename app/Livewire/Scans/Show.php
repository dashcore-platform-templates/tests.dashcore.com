<?php

namespace App\Livewire\Scans;

use App\Enums\MutationResult;
use App\Enums\SurfaceChange;
use App\Enums\TestOutcome;
use App\Models\Scan;
use App\Models\ScanSurface;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One scan: what it ran, what went wrong, and the tests worth a look —
 * failing ones, and passing ones that assert nothing.
 */
class Show extends Component
{
    #[Locked]
    public int $scanId;

    public function mount(int $scanId): void
    {
        $this->scanId = Scan::query()->findOrFail($scanId)->id;
    }

    #[Computed]
    public function scan(): Scan
    {
        return Scan::with('fleetApp', 'requestedBy')->findOrFail($this->scanId);
    }

    #[Computed]
    public function failing(): Collection
    {
        return $this->scan->tests()
            ->whereIn('outcome', [TestOutcome::Failed, TestOutcome::Errored])
            ->orderBy('file')
            ->get();
    }

    /**
     * Passing tests with no assertions, and tests PHPUnit flagged as risky:
     * they are green and prove nothing.
     */
    #[Computed]
    public function hollow(): Collection
    {
        return $this->scan->tests()
            ->where(fn ($q) => $q
                ->where('outcome', TestOutcome::Risky)
                ->orWhere(fn ($q) => $q->where('outcome', TestOutcome::Passed)->where('assertions', 0)))
            ->orderBy('file')
            ->limit(200)
            ->get();
    }

    /**
     * What this scan flagged against the one before it: coverage lost, and
     * new surfaces no test proves.
     *
     * @return Collection<int, ScanSurface>
     */
    #[Computed]
    public function regressions(): Collection
    {
        return $this->scan->surfaceResults()
            ->with('surface')
            ->whereIn('change', [SurfaceChange::Lost, SurfaceChange::NewUntested])
            ->orderBy('change')
            ->limit(200)
            ->get();
    }

    /**
     * Tested methods whose body was swapped for a trivial default without
     * any of their tests failing.
     *
     * @return Collection<int, ScanSurface>
     */
    #[Computed]
    public function survivors(): Collection
    {
        return $this->scan->surfaceResults()
            ->with('surface')
            ->where('mutation', MutationResult::Survived)
            ->limit(200)
            ->get();
    }

    #[Computed]
    public function slowest(): Collection
    {
        return $this->scan->tests()->orderByDesc('duration_ms')->limit(10)->get();
    }

    public function render()
    {
        return view('livewire.scans.show');
    }
}
