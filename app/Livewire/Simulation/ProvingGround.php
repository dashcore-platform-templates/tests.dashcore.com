<?php

namespace App\Livewire\Simulation;

use App\Enums\FindingStatus;
use App\Enums\SimulationStatus;
use App\Models\SimulationFinding;
use App\Models\SimulationRun;
use App\Models\SimulationStep;
use App\Services\PermissionService;
use App\Simulation\Simulation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The Proving Ground: one switch, and the fake company at work behind it.
 * Follows the live run by default; picking a run from the feed replays it
 * step by step until "Back to live".
 */
class ProvingGround extends Component
{
    /** The run being replayed; null follows whatever is live. */
    public ?int $runId = null;

    /** The step being looked at; null follows the newest. */
    public ?int $stepPosition = null;

    /** Which findings are listed: open, resolved or ignored. */
    public string $findingsTab = 'open';

    public function toggle(Simulation $simulation): void
    {
        $this->authorizeFull();

        $simulation->isOn() ? $simulation->turnOff() : $simulation->turnOn();

        unset($this->on);
    }

    public function watch(int $runId): void
    {
        $this->runId = SimulationRun::query()->whereKey($runId)->value('id');
        $this->stepPosition = null;
    }

    public function showStep(int $position): void
    {
        $this->stepPosition = $position;
    }

    /**
     * Replay the last run that hit a finding, on the step where it did.
     */
    public function replayFinding(int $findingId): void
    {
        $finding = SimulationFinding::query()->findOrFail($findingId);

        if ($finding->last_run_id === null) {
            return;
        }

        $this->runId = $finding->last_run_id;
        $this->stepPosition = $finding->last_position;
    }

    public function ignoreFinding(int $findingId): void
    {
        $this->authorizeFull();

        SimulationFinding::query()->whereKey($findingId)->update(['status' => FindingStatus::Ignored]);

        unset($this->findings, $this->findingCounts);
    }

    public function reopenFinding(int $findingId): void
    {
        $this->authorizeFull();

        SimulationFinding::query()->whereKey($findingId)->update([
            'status' => FindingStatus::Open,
            'resolved_at' => null,
            'clean_runs' => 0,
        ]);

        unset($this->findings, $this->findingCounts);
    }

    public function showFindings(string $tab): void
    {
        $this->findingsTab = FindingStatus::tryFrom($tab)?->value ?? FindingStatus::Open->value;
    }

    public function follow(): void
    {
        $this->runId = null;
        $this->stepPosition = null;
    }

    #[Computed]
    public function on(): bool
    {
        return app(Simulation::class)->isOn();
    }

    #[Computed]
    public function workerAlive(): bool
    {
        return app(Simulation::class)->workerAlive();
    }

    #[Computed]
    public function workerSeenAt(): ?Carbon
    {
        return app(Simulation::class)->workerSeenAt();
    }

    #[Computed]
    public function onSince(): ?Carbon
    {
        return app(Simulation::class)->onSince();
    }

    #[Computed]
    public function canManage(): bool
    {
        return app(PermissionService::class)->hasFullAccessForUser(auth()->user(), 'fleet-tests');
    }

    /**
     * The run on screen: the one being replayed, else the one running now,
     * else the last one to finish.
     */
    #[Computed]
    public function run(): ?SimulationRun
    {
        $query = SimulationRun::query()->with('steps');

        if ($this->runId !== null) {
            return $query->find($this->runId);
        }

        return (clone $query)->where('status', SimulationStatus::Running)->latest('id')->first()
            ?? $query->latest('id')->first();
    }

    #[Computed]
    public function step(): ?SimulationStep
    {
        $run = $this->run;

        if ($run === null) {
            return null;
        }

        if ($this->stepPosition !== null) {
            return $run->steps->firstWhere('position', $this->stepPosition);
        }

        return $run->status === SimulationStatus::Failed
            ? ($run->failedStep() ?? $run->latestScreenshotStep())
            : $run->latestScreenshotStep();
    }

    /**
     * Worst first: kind by how much it hurts, then most recently seen.
     *
     * @return Collection<int, SimulationFinding>
     */
    #[Computed]
    public function findings(): Collection
    {
        return SimulationFinding::query()
            ->where('status', FindingStatus::tryFrom($this->findingsTab) ?? FindingStatus::Open)
            ->latest('last_seen_at')
            ->limit(100)
            ->get()
            ->sortBy(fn (SimulationFinding $finding) => [$finding->kind->rank(), -$finding->last_seen_at->getTimestamp()])
            ->values();
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function findingCounts(): array
    {
        $counts = SimulationFinding::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        return [
            ...array_fill_keys(array_column(FindingStatus::cases(), 'value'), 0),
            ...array_map('intval', $counts),
            'regressions' => SimulationFinding::query()->where('status', FindingStatus::Open)->whereNotNull('reopened_at')->count(),
        ];
    }

    /**
     * @return Collection<int, SimulationRun>
     */
    #[Computed]
    public function feed(): Collection
    {
        return SimulationRun::query()
            ->whereNot('status', SimulationStatus::Running)
            ->latest('id')
            ->limit(30)
            ->get();
    }

    /**
     * Totals since the switch was last turned on, or for the last day when
     * it is off.
     *
     * @return array{label: string, runs: int, passed: int, failed: int, rate: ?float, avg_seconds: ?float}
     */
    #[Computed]
    public function totals(): array
    {
        $since = $this->onSince ?? now()->subDay();

        $row = SimulationRun::query()
            ->where('created_at', '>=', $since)
            ->whereNot('status', SimulationStatus::Running)
            ->selectRaw('count(*) as runs')
            ->selectRaw('count(*) filter (where status = ?) as passed', [SimulationStatus::Passed->value])
            ->selectRaw('count(*) filter (where status in (?, ?)) as failed', [SimulationStatus::Failed->value, SimulationStatus::Stopped->value])
            ->selectRaw('avg(duration_ms) as avg_ms')
            ->first();

        $runs = (int) $row->runs;

        return [
            'label' => $this->onSince ? 'since switched on' : 'in the last 24 hours',
            'runs' => $runs,
            'passed' => (int) $row->passed,
            'failed' => (int) $row->failed,
            'rate' => $runs === 0 ? null : round($row->passed / $runs * 100, 1),
            'avg_seconds' => $row->avg_ms === null ? null : round($row->avg_ms / 1000, 1),
        ];
    }

    public function render()
    {
        return view('livewire.simulation.proving-ground');
    }

    private function authorizeFull(): void
    {
        abort_unless($this->canManage, 403);
    }
}
