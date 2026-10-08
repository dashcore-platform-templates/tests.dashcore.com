<?php

namespace App\Livewire;

use App\Enums\FindingStatus;
use App\Enums\ScanKind;
use App\Enums\ScanStatus;
use App\Models\Build;
use App\Models\FleetApp;
use App\Models\Scan;
use App\Models\SimulationFinding;
use App\Simulation\Simulation;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Dashboard extends Component
{
    #[Computed]
    public function fleetSummary(): array
    {
        $apps = FleetApp::with('latestCompletedScan')->get();
        $scans = $apps->pluck('latestCompletedScan')->filter();
        $total = (int) $scans->sum('surfaces_total');
        $tested = (int) $scans->sum('surfaces_tested');

        return [
            'apps' => $apps->count(),
            'scanned' => $scans->count(),
            'score' => $total === 0 ? null : round($tested / $total * 100, 1),
            'tested' => $tested,
            'total' => $total,
            'missing' => (int) $scans->sum('surfaces_missing'),
            'weak' => (int) $scans->sum('surfaces_weak'),
            'failing' => (int) $scans->sum('tests_failed'),
        ];
    }

    /**
     * @return Collection<int, Scan>
     */
    #[Computed]
    public function activeScans(): Collection
    {
        return Scan::with('fleetApp')
            ->whereIn('status', [
                ScanStatus::Queued,
                ScanStatus::Preparing,
                ScanStatus::Running,
                ScanStatus::Analysing,
                ScanStatus::Mutating,
            ])
            ->latest()
            ->get();
    }

    /**
     * @return Collection<int, Scan>
     */
    #[Computed]
    public function recentScans(): Collection
    {
        return Scan::with('fleetApp')
            ->where('kind', ScanKind::Main)
            ->where('status', ScanStatus::Completed)
            ->latest()
            ->limit(8)
            ->get();
    }

    /**
     * @return Collection<int, Build>
     */
    #[Computed]
    public function activeBuilds(): Collection
    {
        return Build::with('fleetApp')
            ->holdingTargets()
            ->latest()
            ->limit(8)
            ->get();
    }

    #[Computed]
    public function simulationStatus(): array
    {
        $sim = app(Simulation::class);

        return [
            'on' => $sim->isOn(),
            'workerAlive' => $sim->workerAlive(),
            'openFindings' => SimulationFinding::where('status', FindingStatus::Open)->count(),
        ];
    }

    #[Computed]
    public function anyActive(): bool
    {
        return $this->activeScans->isNotEmpty()
            || $this->activeBuilds->contains(fn (Build $b) => $b->status->isActive());
    }
}
