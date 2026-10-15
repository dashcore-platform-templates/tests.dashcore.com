<?php

namespace App\Livewire\Workflows;

use App\Models\Workflow;
use App\Services\PermissionService;
use App\Workflows\Transfer;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Every workflow this install keeps, with how each last went.
 */
class Index extends Component
{
    use WithFileUploads;

    /** A workflows export chosen to import; read as soon as it is chosen. */
    public $file = null;

    /** What the last import made. */
    public ?string $notice = null;

    public function toggle(int $workflowId): void
    {
        abort_unless($this->canManage, 403);

        Workflow::query()->findOrFail($workflowId)->toggleActive();

        unset($this->workflows);
    }

    public function runNow(int $workflowId): void
    {
        abort_unless($this->canManage, 403);

        Workflow::query()->findOrFail($workflowId)->requestRun();

        unset($this->workflows);
    }

    /**
     * Make new workflows, switched off, from the chosen export file.
     */
    public function updatedFile(): void
    {
        abort_unless($this->canManage, 403);
        $this->notice = null;

        try {
            $this->validate(['file' => ['required', 'file', 'extensions:json', 'max:1024']], attributes: ['file' => 'file']);

            $imported = Transfer::import((string) file_get_contents($this->file->getRealPath()), auth()->user(), $this->file->getClientOriginalName());
        } finally {
            $this->file = null;
        }

        $this->notice = ($imported->count() === 1
            ? "Imported \"{$imported->first()->name}\". It is switched off — run it by hand, then switch it on."
            : "Imported {$imported->count()} workflows. They are switched off — run each by hand, then switch it on.");

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
