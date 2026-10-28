<?php

namespace Tests\Feature\Demo;

use App\Enums\SimulationStatus;
use App\Enums\WorkflowEventType;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowEvent;
use App\Models\WorkflowRun;
use App\Workflows\WorkflowRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesDemoScratch;
use Tests\TestCase;

class DemoWorkflowsTest extends TestCase
{
    use RefreshDatabase, UsesDemoScratch;

    private const STARTERS = ['Website: lets-talk form takes an enquiry', 'Website: main pages answer', 'Sales stand-in: a rep signs in', 'Sales stand-in: the leads list opens'];

    public function test_the_starter_workflows_show_a_passed_and_a_failed_run_each(): void
    {
        $owner = User::factory()->owner()->create();

        $this->artisan('tests:demo')->assertSuccessful();

        $this->assertEqualsCanonicalizing(self::STARTERS, Workflow::pluck('name')->all());

        foreach (Workflow::all() as $workflow) {
            $this->assertFalse($workflow->active, $workflow->name);
            $this->assertEqualsCanonicalizing([SimulationStatus::Passed, SimulationStatus::Failed], $workflow->runs()->pluck('status')->all(), $workflow->name);
        }

        $this->actingAs($owner)->get(route('workflows.index'))
            ->assertOk()
            ->assertSee(self::STARTERS);

        $workflow = Workflow::query()->where('name', 'Website: main pages answer')->sole();
        $failed = $workflow->runs()->where('status', SimulationStatus::Failed)->sole();
        $passed = $workflow->runs()->where('status', SimulationStatus::Passed)->sole();

        $this->actingAs($owner)->get(route('workflows.show', ['workflow' => $workflow, 'run' => $failed->id]))
            ->assertOk()
            ->assertSee(['Failed · 3 of 6 steps', 'the page answered 502'])
            ->assertSee(route('workflows.screenshot', [$failed, '003.jpg']), false);

        $this->actingAs($owner)->get(route('workflows.screenshot', [$failed, '003.jpg']))->assertOk()->assertHeader('content-type', 'image/jpeg');
        $this->actingAs($owner)->get(route('workflows.screenshot', [$passed, '005.jpg']))->assertOk();

        $this->actingAs($owner)->get(route('workflows.health'))
            ->assertOk()
            ->assertSee(self::STARTERS)
            ->assertSee('Demo run — nothing was opened.');
    }

    public function test_running_it_again_reuses_the_starters_and_wipe_keeps_them_switched_off(): void
    {
        $this->artisan('tests:demo')->assertSuccessful();
        $this->artisan('tests:demo')->assertSuccessful();

        $this->assertSame(4, Workflow::count());
        $this->assertSame(8, WorkflowRun::count());
        $this->assertSame(4, WorkflowEvent::query()->where('type', WorkflowEventType::Imported)->count());
        $shots = WorkflowRunner::screenshotDirectory(WorkflowRun::first());
        $this->assertDirectoryExists($shots);

        $this->artisan('tests:demo --wipe')->assertSuccessful();

        $this->assertEqualsCanonicalizing(self::STARTERS, Workflow::pluck('name')->all());
        $this->assertSame(0, WorkflowRun::count());
        $this->assertSame(0, WorkflowEvent::query()->where('is_demo', true)->count());
        $this->assertDirectoryDoesNotExist($shots);

        foreach (Workflow::all() as $workflow) {
            $this->assertFalse($workflow->active);
            $this->assertNull($workflow->last_status);
            $this->assertNull($workflow->last_run_at);
            $this->assertSame(0, $workflow->consecutive_failures);
        }
    }

    public function test_wipe_leaves_a_real_run_of_a_starter_and_its_result(): void
    {
        $this->artisan('tests:demo')->assertSuccessful();
        $workflow = Workflow::query()->where('name', 'Sales stand-in: a rep signs in')->sole();
        $real = WorkflowRun::factory()->for($workflow)->create(['status' => SimulationStatus::Passed, 'finished_at' => now()]);

        $this->artisan('tests:demo --wipe')->assertSuccessful();

        $this->assertSame([$real->id], WorkflowRun::pluck('id')->all());
        $this->assertSame(SimulationStatus::Passed, $workflow->refresh()->last_status);
    }
}
