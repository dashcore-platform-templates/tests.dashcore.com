<?php

namespace Tests\Feature\Workflows;

use App\Enums\SimulationStatus;
use App\Enums\WorkflowFrequency;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class RunWorkflowsCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $shots;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shots = sys_get_temp_dir().'/wf-'.bin2hex(random_bytes(4));
        config(['workflows.screenshots' => $this->shots]);
        Notification::fake();
        Process::fake(['*' => Process::result(output: json_encode(['event' => 'finished', 'status' => 'failed']))]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->shots);

        parent::tearDown();
    }

    public function test_it_walks_only_what_is_due_and_books_the_next_turn(): void
    {
        $this->freezeSecond();
        $due = Workflow::factory()->active()->create(['frequency' => WorkflowFrequency::Every15Minutes]);
        $later = Workflow::factory()->create(['active' => true, 'next_run_at' => now()->addMinutes(5)]);
        $off = Workflow::factory()->create(['active' => false, 'next_run_at' => now()->subHour()]);

        $this->artisan('tests:workflows')->assertSuccessful();

        $this->assertSame(1, $due->runs()->count());
        $this->assertSame('schedule', $due->runs()->value('trigger'));
        $this->assertTrue($due->refresh()->next_run_at->eq(now()->addMinutes(15)));
        $this->assertSame(0, $later->runs()->count());
        $this->assertSame(0, $off->runs()->count());
    }

    public function test_run_now_walks_a_switched_off_workflow_once_without_scheduling_it(): void
    {
        $workflow = Workflow::factory()->create(['active' => false, 'run_requested_at' => now()]);

        $this->artisan('tests:workflows')->assertSuccessful();
        $this->artisan('tests:workflows')->assertSuccessful();

        $this->assertSame(1, $workflow->runs()->count());
        $this->assertSame('manual', $workflow->runs()->value('trigger'));
        $workflow->refresh();
        $this->assertNull($workflow->run_requested_at);
        $this->assertNull($workflow->next_run_at);
    }

    public function test_a_manual_workflow_never_walks_on_its_own(): void
    {
        $workflow = Workflow::factory()->create(['active' => true, 'frequency' => WorkflowFrequency::Manual, 'next_run_at' => null]);

        $this->artisan('tests:workflows')->assertSuccessful();

        $this->assertSame(0, $workflow->runs()->count());
    }

    public function test_naming_a_workflow_walks_it_now(): void
    {
        $workflow = Workflow::factory()->create();

        $this->artisan('tests:workflows', ['workflow' => $workflow->id])
            ->expectsOutputToContain('Contact form sends: failed')
            ->assertSuccessful();

        $this->assertSame(1, $workflow->runs()->count());
        $this->artisan('tests:workflows', ['workflow' => 999])->assertFailed();
    }

    public function test_a_run_cut_off_long_ago_is_marked_stopped(): void
    {
        $run = WorkflowRun::factory()->create(['status' => SimulationStatus::Running, 'started_at' => now()->subHour(), 'finished_at' => null]);

        $this->artisan('tests:workflows')->assertSuccessful();

        $this->assertSame(SimulationStatus::Stopped, $run->refresh()->status);
    }

    public function test_old_runs_lose_their_pictures_then_their_records(): void
    {
        config(['workflows.keep_runs' => 1, 'workflows.prune_days' => 30]);
        $workflow = Workflow::factory()->create();
        $ancient = WorkflowRun::factory()->for($workflow)->create(['created_at' => now()->subDays(40)]);
        $older = WorkflowRun::factory()->for($workflow)->create(['created_at' => now()->subDay()]);
        $newest = WorkflowRun::factory()->for($workflow)->create();
        File::ensureDirectoryExists("{$this->shots}/{$older->id}");

        $this->artisan('tests:workflows')->assertSuccessful();

        $this->assertModelMissing($ancient);
        $this->assertTrue($older->refresh()->screenshots_pruned);
        $this->assertDirectoryDoesNotExist("{$this->shots}/{$older->id}");
        $this->assertFalse($newest->refresh()->screenshots_pruned);
    }

    public function test_it_is_on_the_schedule_every_minute(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'tests:workflows'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
