<?php

namespace App\Livewire\Simulation;

use App\Models\JourneyRun;
use App\Models\Launch;
use App\Simulation\JourneyMap as Map;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The client journey map: exactly how a new client is brought on, drawn
 * from the latest launch flown against the practice fleet — every stage in
 * order, the apps it crossed, how the client got from one to the next, and
 * each step's screenshot. An earlier launch can be picked to compare.
 */
class JourneyMap extends Component
{
    /** The launch drawn; null draws the latest. */
    #[Url(as: 'launch')]
    public ?int $launchId = null;

    /** The stage opened below the map. */
    #[Url(as: 'stage')]
    public ?string $stageKey = null;

    /** The step whose screenshot is shown: "walk:position". */
    public ?string $shot = null;

    #[Computed]
    public function launch(): ?Launch
    {
        return $this->launchId === null
            ? Launch::query()->latest('id')->first()
            : Launch::query()->find($this->launchId);
    }

    /** @return Collection<int, Launch> */
    #[Computed]
    public function launches(): Collection
    {
        return Launch::query()->latest('id')->limit(10)->get(['id', 'outcome', 'started_at', 'finished_at']);
    }

    /** @return list<array<string, mixed>> */
    #[Computed]
    public function stages(): array
    {
        return app(Map::class)->stages($this->launch);
    }

    /** @return array<string, mixed>|null */
    #[Computed]
    public function open(): ?array
    {
        $stages = collect($this->stages);

        return $stages->firstWhere('key', $this->stageKey)
            ?? $stages->firstWhere('status', 'failed')
            ?? $stages->firstWhere('status', 'passed');
    }

    /**
     * The latest walk of each customer journey (`tests:journey`), drifted
     * ones marked: a walk on a stand-in off its live release proves the
     * stand-in, not what clients use.
     *
     * @return Collection<int, JourneyRun>
     */
    #[Computed]
    public function walks(): Collection
    {
        return JourneyRun::query()
            ->whereIn('id', JourneyRun::query()->selectRaw('max(id)')->groupBy('journey', 'fleet'))
            ->latest('id')
            ->limit(20)
            ->get();
    }

    public function openStage(string $key): void
    {
        $this->stageKey = $key;
        $this->shot = null;
    }

    public function showShot(int $walk, int $position): void
    {
        $this->shot = "{$walk}:{$position}";
    }

    public function pick(?int $launch): void
    {
        $this->launchId = $launch;
        $this->stageKey = null;
        $this->shot = null;
    }

    public function render()
    {
        return view('livewire.simulation.journey-map', ['map' => app(Map::class)]);
    }
}
