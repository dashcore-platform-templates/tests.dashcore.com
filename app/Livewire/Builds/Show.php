<?php

namespace App\Livewire\Builds;

use App\Models\Build;
use App\Models\Surface;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One build: what it was asked to cover, this app's verdict on each target,
 * what the agent reported, and the PR if one was opened.
 */
class Show extends Component
{
    #[Locked]
    public int $buildId;

    public function mount(int $buildId): void
    {
        $this->buildId = Build::query()->findOrFail($buildId)->id;
    }

    #[Computed]
    public function build(): Build
    {
        return Build::with('fleetApp', 'requestedBy')->findOrFail($this->buildId);
    }

    /**
     * @return Collection<int, Surface>
     */
    #[Computed]
    public function targets(): Collection
    {
        return Surface::query()->whereIn('id', $this->build->targets)->get()->keyBy('id');
    }

    public function render()
    {
        return view('livewire.builds.show');
    }
}
