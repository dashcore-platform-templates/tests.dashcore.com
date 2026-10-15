<?php

namespace App\Livewire\Workflows;

use App\Enums\WorkflowEventType;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Services\PermissionService;
use App\Workflows\EventLog;
use App\Workflows\WorkflowRunner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One workflow: its steps, its runs, and each run step by step with what
 * the browser saw.
 */
class Show extends Component
{
    public int $workflowId;

    /** The run being looked at; null shows the newest. */
    #[Url(as: 'run')]
    public ?int $runId = null;

    public function mount(Workflow $workflow): void
    {
        $this->workflowId = $workflow->id;
    }

    public function toggle(): void
    {
        abort_unless($this->canManage, 403);

        $this->workflow->toggleActive();
        unset($this->workflow);
    }

    public function runNow(): void
    {
        abort_unless($this->canManage, 403);

        $this->workflow->requestRun();
        $this->runId = null;
        unset($this->workflow);
    }

    public function showRun(int $runId): void
    {
        $this->runId = WorkflowRun::query()->where('workflow_id', $this->workflowId)->whereKey($runId)->value('id');
    }

    public function delete(): void
    {
        abort_unless($this->canManage, 403);

        $workflow = $this->workflow;
        $directories = $workflow->runs()->get()->map(fn (WorkflowRun $run) => WorkflowRunner::screenshotDirectory($run));

        DB::transaction(function () use ($workflow) {
            EventLog::record(WorkflowEventType::Deleted, $workflow, 'Deleted, with its '.$workflow->runs()->count().' run(s). Its lines in this log stay.');
            $workflow->delete();
        });
        $directories->each(fn (string $directory) => File::deleteDirectory($directory));

        session()->flash('workflow-notice', "Deleted \"{$workflow->name}\".");
        $this->redirectRoute('workflows.index', navigate: true);
    }

    #[Computed]
    public function workflow(): Workflow
    {
        return Workflow::query()->with('latestRun')->findOrFail($this->workflowId);
    }

    #[Computed]
    public function runs(): Collection
    {
        return $this->workflow->runs()->limit(30)->get();
    }

    #[Computed]
    public function run(): ?WorkflowRun
    {
        $run = $this->runId
            ? WorkflowRun::query()->where('workflow_id', $this->workflowId)->find($this->runId)
            : $this->workflow->runs()->first();

        return $run?->load('steps');
    }

    #[Computed]
    public function canManage(): bool
    {
        return app(PermissionService::class)->hasFullAccessForUser(auth()->user(), 'workflows');
    }

    public function render()
    {
        return view('livewire.workflows.show');
    }
}
