<?php

namespace App\Livewire\Workflows;

use App\Enums\WorkflowEventType;
use App\Models\Workflow;
use App\Models\WorkflowEvent;
use App\Workflows\Health as HealthReport;
use App\Workflows\Heartbeat;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * How every workflow has been doing, and the log of everything that
 * happened to them: runs, alerts, changes, and the schedule itself.
 */
class Health extends Component
{
    use WithPagination;

    /** Show only this workflow's lines; null shows all. */
    #[Url(as: 'workflow')]
    public ?int $workflowId = null;

    /** One of WorkflowEventType::GROUPS, or '' for everything. */
    #[Url(as: 'show')]
    public string $group = '';

    public function updatedWorkflowId(): void
    {
        $this->resetPage();
    }

    public function updatedGroup(): void
    {
        $this->resetPage();
    }

    public function filterTo(?int $workflowId, string $group = ''): void
    {
        $this->workflowId = $workflowId;
        $this->group = $group;
        $this->resetPage();
    }

    #[Computed]
    public function workflows(): Collection
    {
        return Workflow::query()->with('latestRun')->orderBy('name')->get();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function health(): array
    {
        return app(HealthReport::class)->forWorkflows($this->workflows);
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function summary(): array
    {
        $workflows = $this->workflows;
        $report = app(HealthReport::class);
        $ids = $workflows->pluck('id')->all();

        return [
            'total' => $workflows->count(),
            'on' => $workflows->where('active', true)->count(),
            'failing' => $workflows->filter(fn (Workflow $w) => $w->consecutive_failures > 0)->count(),
            'alerting' => $workflows->where('alerting', true)->count(),
            'uptime_24h' => $report->overall($ids, 1),
            'uptime_7d' => $report->overall($ids, 7),
            'problems_24h' => WorkflowEvent::query()
                ->whereIn('type', WorkflowEventType::inGroup('problems'))
                ->where('created_at', '>=', now()->subDay())
                ->count(),
            'last_tick' => Heartbeat::lastTick(),
        ];
    }

    #[Computed]
    public function events(): LengthAwarePaginator
    {
        $groups = array_keys(WorkflowEventType::GROUPS);

        return WorkflowEvent::query()
            ->with('user:id,name')
            ->when($this->workflowId, fn ($query) => $query->where('workflow_id', $this->workflowId))
            ->when(in_array($this->group, $groups, true), fn ($query) => $query->whereIn('type', WorkflowEventType::inGroup($this->group)))
            ->orderByDesc('id')
            ->paginate(30);
    }

    public function scheduleIsStale(?Carbon $lastTick): bool
    {
        return $lastTick === null || $lastTick->lt(now()->subMinutes((int) config('workflows.stale_after_minutes')));
    }

    public function render()
    {
        return view('livewire.workflows.health', [
            'groups' => WorkflowEventType::GROUPS,
        ]);
    }
}
