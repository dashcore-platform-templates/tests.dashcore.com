<?php

namespace Tests\Feature\Workflows;

use App\Enums\SimulationStatus;
use App\Enums\WorkflowEventType;
use App\Livewire\Workflows\Health;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowEvent;
use App\Models\WorkflowRun;
use App\Workflows\Health as HealthReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkflowHealthTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(Workflow $workflow, SimulationStatus $status, string $ago, int $ms = 5000): WorkflowRun
    {
        $at = now()->sub($ago);

        return WorkflowRun::factory()->for($workflow)->create([
            'status' => $status,
            'duration_ms' => $ms,
            'steps_passed' => $status === SimulationStatus::Passed ? 4 : 1,
            'created_at' => $at,
            'started_at' => $at,
            'finished_at' => $at,
        ]);
    }

    public function test_it_counts_how_often_each_workflow_passed_over_a_day_a_week_and_a_month(): void
    {
        $workflow = Workflow::factory()->create();
        $this->makeRun($workflow, SimulationStatus::Passed, '40 days');
        $this->makeRun($workflow, SimulationStatus::Passed, '20 days');
        $this->makeRun($workflow, SimulationStatus::Failed, '10 days');
        $this->makeRun($workflow, SimulationStatus::Stopped, '3 days');
        $this->makeRun($workflow, SimulationStatus::Passed, '2 days');
        $this->makeRun($workflow, SimulationStatus::Passed, '2 hours');
        $this->makeRun($workflow, SimulationStatus::Running, '1 minute');

        $health = app(HealthReport::class)->forWorkflows(collect([$workflow]))[$workflow->id];

        $this->assertSame(['24h' => 100.0, '7d' => 66.7, '30d' => 60.0], $health['uptime']);
        $this->assertSame(['24h' => 1, '7d' => 3, '30d' => 5], $health['runs']);
        $this->assertSame(6, $health['strip']->count(), 'Finished runs only, the running one left out.');
        $this->assertTrue($health['strip']->first()->created_at->lt($health['strip']->last()->created_at), 'Oldest first.');
    }

    public function test_it_knows_the_current_streak_the_last_failure_and_the_step_that_breaks_most(): void
    {
        $workflow = Workflow::factory()->create();
        $failed = $this->makeRun($workflow, SimulationStatus::Failed, '5 hours');
        $failed->steps()->create(['position' => 1, 'label' => 'Type an email', 'action' => 'fill', 'status' => SimulationStatus::Failed]);
        $other = $this->makeRun($workflow, SimulationStatus::Failed, '4 hours');
        $other->steps()->create(['position' => 1, 'label' => 'Type an email', 'action' => 'fill', 'status' => SimulationStatus::Failed]);
        $this->makeRun($workflow, SimulationStatus::Passed, '3 hours');
        $this->makeRun($workflow, SimulationStatus::Passed, '2 hours');
        $this->makeRun($workflow, SimulationStatus::Passed, '1 hour');

        $health = app(HealthReport::class)->forWorkflows(collect([$workflow]))[$workflow->id];

        $this->assertSame(['status' => 'passing', 'count' => 3], $health['streak']);
        $this->assertTrue($health['last_failure']->eq($other->created_at));
        $this->assertSame(['label' => 'Type an email', 'failures' => 2], $health['worst_step']);
    }

    public function test_a_failing_streak_and_a_workflow_never_run(): void
    {
        $failing = Workflow::factory()->create(['name' => 'A']);
        $this->makeRun($failing, SimulationStatus::Passed, '3 hours');
        $this->makeRun($failing, SimulationStatus::Failed, '2 hours');
        $this->makeRun($failing, SimulationStatus::Stopped, '1 hour');
        $never = Workflow::factory()->create(['name' => 'B']);

        $health = app(HealthReport::class)->forWorkflows(collect([$failing, $never]));

        $this->assertSame(['status' => 'failing', 'count' => 2], $health[$failing->id]['streak']);
        $this->assertSame(['status' => 'none', 'count' => 0], $health[$never->id]['streak']);
        $this->assertSame(['24h' => null, '7d' => null, '30d' => null], $health[$never->id]['uptime']);
        $this->assertNull($health[$never->id]['avg_ms']);
        $this->assertNull($health[$never->id]['worst_step']);
    }

    public function test_typical_and_slow_times_come_from_passed_runs_this_week(): void
    {
        $workflow = Workflow::factory()->create();

        foreach (range(1, 20) as $i) {
            $this->makeRun($workflow, SimulationStatus::Passed, "{$i} hours", $i * 1000);
        }

        $this->makeRun($workflow, SimulationStatus::Failed, '30 minutes', 180000);
        $this->makeRun($workflow, SimulationStatus::Passed, '9 days', 900000);

        $health = app(HealthReport::class)->forWorkflows(collect([$workflow]))[$workflow->id];

        $this->assertSame(10500, $health['avg_ms']);
        $this->assertSame(19000, $health['p95_ms']);
    }

    public function test_the_page_shows_each_workflow_and_the_log(): void
    {
        $workflow = Workflow::factory()->create(['name' => 'Lets-talk form is ready']);
        $run = $this->makeRun($workflow, SimulationStatus::Passed, '1 hour');
        WorkflowEvent::query()->create(['type' => WorkflowEventType::RunPassed, 'workflow_id' => $workflow->id, 'workflow_name' => $workflow->name, 'workflow_run_id' => $run->id, 'message' => 'Passed all 7 steps in 7.0s, on schedule.']);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('workflows.health'))
            ->assertOk()
            ->assertSee('Workflow health')
            ->assertSee('Lets-talk form is ready')
            ->assertSee('Passing · 1 in a row')
            ->assertSee('Passed all 7 steps in 7.0s, on schedule.')
            ->assertSee(route('workflows.health'));
    }

    public function test_members_cannot_see_it(): void
    {
        $this->actingAs(User::factory()->create())->get(route('workflows.health'))->assertForbidden();
    }

    public function test_the_log_filters_by_workflow_and_by_kind(): void
    {
        $a = Workflow::factory()->create(['name' => 'A']);
        $b = Workflow::factory()->create(['name' => 'B']);
        $log = fn (Workflow $w, WorkflowEventType $type, string $message) => WorkflowEvent::query()->create(['type' => $type, 'workflow_id' => $w->id, 'workflow_name' => $w->name, 'message' => $message]);
        $log($a, WorkflowEventType::RunPassed, 'a passed');
        $log($a, WorkflowEventType::RunFailed, 'a failed');
        $log($b, WorkflowEventType::AlertSent, 'b alerted');
        $log($b, WorkflowEventType::Updated, 'b changed');
        WorkflowEvent::query()->create(['type' => WorkflowEventType::ScheduleResumed, 'message' => 'schedule gap']);

        $page = Livewire::actingAs(User::factory()->admin()->create())->test(Health::class);
        $messages = fn () => $page->instance()->events->pluck('message')->sort()->values()->all();

        $this->assertSame(['a failed', 'a passed', 'b alerted', 'b changed', 'schedule gap'], $messages());

        $page->set('group', 'problems');
        $this->assertSame(['a failed', 'b alerted', 'schedule gap'], $messages());

        $page->call('filterTo', $a->id);
        $this->assertSame(['a failed', 'a passed'], $messages());

        $page->set('group', 'changes')->set('workflowId', $b->id);
        $this->assertSame(['b changed'], $messages());
    }

    public function test_the_summary_counts_what_is_failing_and_problems_today(): void
    {
        Workflow::factory()->create(['active' => true, 'consecutive_failures' => 2, 'alerting' => true]);
        Workflow::factory()->create(['active' => true]);
        Workflow::factory()->create(['active' => false]);
        WorkflowEvent::query()->create(['type' => WorkflowEventType::RunFailed, 'message' => 'x']);
        WorkflowEvent::query()->create(['type' => WorkflowEventType::RunPassed, 'message' => 'y']);
        WorkflowEvent::query()->create(['type' => WorkflowEventType::AlertSent, 'message' => 'z', 'created_at' => now()->subDays(2)]);

        $summary = Livewire::actingAs(User::factory()->admin()->create())->test(Health::class)->instance()->summary;

        $this->assertSame(3, $summary['total']);
        $this->assertSame(2, $summary['on']);
        $this->assertSame(1, $summary['failing']);
        $this->assertSame(1, $summary['alerting']);
        $this->assertSame(1, $summary['problems_24h']);
    }
}
