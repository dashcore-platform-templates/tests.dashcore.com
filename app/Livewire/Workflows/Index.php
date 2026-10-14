<?php

namespace App\Livewire\Workflows;

use App\Models\Workflow;
use App\Services\PermissionService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Every workflow this install keeps, with how each last went.
 */
class Index extends Component
{
    public function toggle(int $workflowId): void
    {
        abort_unless($this->canManage, 403);

        Workflow::query()->findOrFail($workflowId)->toggleActive();

        unset($this->workflows);
    }

    public function runNow(int $workflowId): void
    {
        abort_unless($this->canManage, 403);

        Workflow::query()->findOrFail($workflowId)->update(['run_requested_at' => now()]);

        unset($this->workflows);
    }

    #[Computed]
    public function workflows(): Collection
    {
        return Workflow::query()->with('latestRun')->orderBy('name')->get();
    }

    #[Computed]
    public function canManage(): bool
    {
        return app(PermissionService::class)->hasFullAccessForUser(auth()->user(), 'workflows');
    }

    public function render()
    {
        return view('livewire.workflows.index');
    }
}
