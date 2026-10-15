<?php

namespace App\Workflows;

use App\Enums\SimulationStatus;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowRunStep;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * How each workflow has been doing: how often it passed over a day, a week
 * and a month, its recent runs at a glance, how long it takes, where it
 * breaks most, and how long its current streak is. Read from its runs —
 * only finished ones count (passed, failed, or cut off).
 */
class Health
{
    public const WINDOWS = ['24h' => 1, '7d' => 7, '30d' => 30];

    public const STRIP = 48;

    /**
     * @param  Collection<int, Workflow>  $workflows
     * @return array<int, array{uptime: array<string, ?float>, runs: array<string, int>, strip: Collection<int, WorkflowRun>, avg_ms: ?int, p95_ms: ?int, worst_step: ?array{label: string, failures: int}, streak: array{status: string, count: int}, last_failure: ?Carbon}>
     */
    public function forWorkflows(Collection $workflows): array
    {
        $ids = $workflows->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $uptime = $this->uptime($ids);
        $durations = $this->durations($ids);
        $worst = $this->worstSteps($ids);

        return $workflows->mapWithKeys(function (Workflow $workflow) use ($uptime, $durations, $worst) {
            $row = $uptime[$workflow->id] ?? null;
            $times = $durations[$workflow->id] ?? [];

            return [$workflow->id => [
                'uptime' => collect(self::WINDOWS)->map(fn ($days, $key) => $row && $row->{"total_{$key}"} > 0
                    ? round($row->{"passed_{$key}"} / $row->{"total_{$key}"} * 100, 1)
                    : null)->all(),
                'runs' => collect(self::WINDOWS)->map(fn ($days, $key) => (int) ($row?->{"total_{$key}"} ?? 0))->all(),
                'strip' => $this->strip($workflow),
                'avg_ms' => $times === [] ? null : (int) round(array_sum($times) / count($times)),
                'p95_ms' => $times === [] ? null : self::percentile($times, 95),
                'worst_step' => $worst[$workflow->id] ?? null,
                'streak' => $this->streak($workflow),
                'last_failure' => WorkflowRun::query()->where('workflow_id', $workflow->id)
                    ->whereIn('status', [SimulationStatus::Failed, SimulationStatus::Stopped])->latest('id')->value('created_at'),
            ]];
        })->all();
    }

    /**
     * Passed runs as a share of all finished runs, across these workflows.
     *
     * @param  list<int>  $ids
     */
    public function overall(array $ids, int $days): ?float
    {
        $runs = $this->finished()->whereIn('workflow_id', $ids)->where('created_at', '>=', now()->subDays($days));
        $total = (clone $runs)->count();

        return $total === 0 ? null : round((clone $runs)->where('status', SimulationStatus::Passed)->count() / $total * 100, 1);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, object>
     */
    private function uptime(array $ids): array
    {
        $select = ['workflow_id'];
        $bindings = [];

        foreach (self::WINDOWS as $key => $days) {
            $since = now()->subDays($days);
            $select[] = "sum(case when created_at >= ? then 1 else 0 end) as total_{$key}";
            $select[] = "sum(case when created_at >= ? and status = ? then 1 else 0 end) as passed_{$key}";
            array_push($bindings, $since, $since, SimulationStatus::Passed->value);
        }

        return $this->finished()
            ->whereIn('workflow_id', $ids)
            ->where('created_at', '>=', now()->subDays(max(self::WINDOWS)))
            ->selectRaw(implode(', ', $select), $bindings)
            ->groupBy('workflow_id')
            ->toBase()
            ->get()
            ->keyBy('workflow_id')
            ->all();
    }

    /**
     * Passed runs' durations over the last week, per workflow.
     *
     * @param  list<int>  $ids
     * @return array<int, list<int>>
     */
    private function durations(array $ids): array
    {
        return WorkflowRun::query()
            ->whereIn('workflow_id', $ids)
            ->where('status', SimulationStatus::Passed)
            ->where('created_at', '>=', now()->subDays(7))
            ->whereNotNull('duration_ms')
            ->toBase()
            ->get(['workflow_id', 'duration_ms'])
            ->groupBy('workflow_id')
            ->map(fn (Collection $rows) => $rows->pluck('duration_ms')->map(fn ($ms) => (int) $ms)->all())
            ->all();
    }

    /**
     * The step each workflow failed at most over the last month.
     *
     * @param  list<int>  $ids
     * @return array<int, array{label: string, failures: int}>
     */
    private function worstSteps(array $ids): array
    {
        return WorkflowRunStep::query()
            ->join('workflow_runs', 'workflow_runs.id', '=', 'workflow_run_steps.workflow_run_id')
            ->whereIn('workflow_runs.workflow_id', $ids)
            ->where('workflow_runs.created_at', '>=', now()->subDays(30))
            ->where('workflow_run_steps.status', SimulationStatus::Failed)
            ->groupBy('workflow_runs.workflow_id', 'workflow_run_steps.label')
            ->select('workflow_runs.workflow_id', 'workflow_run_steps.label', DB::raw('count(*) as failures'))
            ->orderByDesc('failures')
            ->toBase()
            ->get()
            ->groupBy('workflow_id')
            ->map(fn (Collection $rows) => ['label' => $rows->first()->label, 'failures' => (int) $rows->first()->failures])
            ->all();
    }

    /**
     * @return Collection<int, WorkflowRun>
     */
    private function strip(Workflow $workflow): Collection
    {
        return $this->finished()
            ->where('workflow_id', $workflow->id)
            ->orderByDesc('id')
            ->limit(self::STRIP)
            ->get(['id', 'workflow_id', 'status', 'created_at', 'duration_ms', 'error'])
            ->reverse()
            ->values();
    }

    /**
     * How many runs in a row have ended the way the latest did.
     *
     * @return array{status: string, count: int}
     */
    private function streak(Workflow $workflow): array
    {
        $latest = $this->finished()->where('workflow_id', $workflow->id)->orderByDesc('id')->first(['id', 'status']);

        if ($latest === null) {
            return ['status' => 'none', 'count' => 0];
        }

        $passed = $latest->status === SimulationStatus::Passed;
        $breaker = $this->finished()->where('workflow_id', $workflow->id)
            ->when($passed,
                fn ($query) => $query->where('status', '!=', SimulationStatus::Passed),
                fn ($query) => $query->where('status', SimulationStatus::Passed))
            ->max('id');

        $count = $this->finished()->where('workflow_id', $workflow->id)
            ->when($breaker, fn ($query) => $query->where('id', '>', $breaker))
            ->count();

        return ['status' => $passed ? 'passing' : 'failing', 'count' => $count];
    }

    private function finished()
    {
        return WorkflowRun::query()->whereIn('status', [SimulationStatus::Passed, SimulationStatus::Failed, SimulationStatus::Stopped]);
    }

    /**
     * @param  list<int>  $values
     */
    private static function percentile(array $values, int $percent): int
    {
        sort($values);

        return $values[max(0, (int) ceil($percent / 100 * count($values)) - 1)];
    }
}
