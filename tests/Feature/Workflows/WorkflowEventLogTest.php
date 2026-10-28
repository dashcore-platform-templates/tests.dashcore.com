<?php

namespace Tests\Feature\Workflows;

use App\Console\Commands\RunWorkflows;
use App\Enums\SimulationStatus;
use App\Enums\WorkflowEventType;
use App\Enums\WorkflowFrequency;
use App\Livewire\Workflows\Index;
use App\Livewire\Workflows\Show;
use App\Livewire\Workflows\Wizard;
use App\Models\Setting;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowEvent;
use App\Models\WorkflowRun;
use App\Workflows\Transfer;
use App\Workflows\WorkflowRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class WorkflowEventLogTest extends TestCase
{
    use RefreshDatabase;

    private string $shots;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shots = sys_get_temp_dir().'/wf-'.bin2hex(random_bytes(4));
        config(['workflows.screenshots' => $this->shots]);
        Notification::fake();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->shots);

        parent::tearDown();
    }

    private function browserSays(string $status, int $passedSteps = 4): void
    {
        $lines = [];

        for ($i = 0; $i < $passedSteps; $i++) {
            $lines[] = json_encode(['event' => 'step', 'position' => $i, 'status' => 'passed', 'ms' => 50]);
        }

        if ($status === 'failed') {
            $lines[] = json_encode(['event' => 'step', 'position' => $passedSteps, 'status' => 'failed', 'error' => 'No #email on the page']);
        }

        $lines[] = json_encode(['event' => 'finished', 'status' => $status]);
        Process::fake(['*' => Process::result(output: implode("\n", $lines))]);
    }

    /**
     * @return list<string>
     */
    private function types(?Workflow $workflow = null): array
    {
        return WorkflowEvent::query()
            ->when($workflow, fn ($query) => $query->where('workflow_id', $workflow->id))
            ->orderBy('id')->get()->map(fn (WorkflowEvent $event) => $event->type->value)->all();
    }

    public function test_a_passed_run_is_logged_with_how_long_it_took(): void
    {
        $this->browserSays('passed');
        $workflow = Workflow::factory()->create();

        $run = app(WorkflowRunner::class)->run($workflow, WorkflowRun::TRIGGER_SCHEDULE);

        $event = WorkflowEvent::query()->sole();
        $this->assertSame(WorkflowEventType::RunPassed, $event->type);
        $this->assertSame($run->id, $event->workflow_run_id);
        $this->assertSame($workflow->name, $event->workflow_name);
        $this->assertStringStartsWith('Passed all 4 steps in ', $event->message);
        $this->assertStringEndsWith(', on schedule.', $event->message);
    }

    public function test_failures_are_logged_then_the_alert_then_the_recovery(): void
    {
        $workflow = Workflow::factory()->create(['alert_after' => 2, 'notify' => ['ops@example.test']]);
        $runner = app(WorkflowRunner::class);

        $this->browserSays('failed', 1);
        $runner->run($workflow, WorkflowRun::TRIGGER_SCHEDULE);
        $runner->run($workflow->refresh(), WorkflowRun::TRIGGER_MANUAL);

        $this->browserSays('passed');
        $runner->run($workflow->refresh(), WorkflowRun::TRIGGER_SCHEDULE);

        $this->assertSame(['run_failed', 'run_failed', 'alert_sent', 'run_passed', 'recovery_sent'], $this->types());

        $failed = WorkflowEvent::query()->where('type', 'run_failed')->latest('id')->first();
        $this->assertStringContainsString('Failed after 1 of 4 steps', $failed->message);
        $this->assertStringContainsString('run by hand', $failed->message);
        $this->assertStringContainsString('No #email on the page', $failed->message);
        $this->assertSame('Mailed ops@example.test: it failed 2 times in a row.', WorkflowEvent::query()->where('type', 'alert_sent')->value('message'));
        $this->assertSame('Mailed ops@example.test: it passes again.', WorkflowEvent::query()->where('type', 'recovery_sent')->value('message'));
    }

    public function test_an_alert_with_nobody_to_tell_is_logged_as_not_sent(): void
    {
        $this->browserSays('failed', 0);
        $workflow = Workflow::factory()->create(['alert_after' => 1, 'notify' => []]);

        app(WorkflowRunner::class)->run($workflow, WorkflowRun::TRIGGER_SCHEDULE);

        $this->assertSame(['run_failed', 'alert_not_sent'], $this->types());
        $this->assertStringContainsString('no addresses', WorkflowEvent::query()->where('type', 'alert_not_sent')->value('message'));
    }

    public function test_an_alert_mail_that_cannot_go_out_is_logged_with_why(): void
    {
        $this->browserSays('failed', 0);
        Notification::shouldReceive('send')->andThrow(new RuntimeException('Resend said no'));
        $workflow = Workflow::factory()->create(['alert_after' => 1]);

        app(WorkflowRunner::class)->run($workflow, WorkflowRun::TRIGGER_SCHEDULE);

        $this->assertSame(['run_failed', 'alert_not_sent'], $this->types());
        $this->assertStringContainsString('Resend said no', WorkflowEvent::query()->where('type', 'alert_not_sent')->value('message'));
    }

    public function test_building_changing_switching_and_run_now_are_logged_with_who(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(Wizard::class)
            ->set('name', 'Lets-talk reaches us')
            ->set('baseUrl', 'https://dashcore.com')
            ->set('steps', [['do' => 'visit', 'label' => '', 'path' => '/lets-talk']])
            ->call('save', true);

        $workflow = Workflow::query()->sole();

        Livewire::actingAs($admin)->test(Wizard::class, ['workflow' => $workflow])
            ->set('frequency', WorkflowFrequency::Daily->value)
            ->set('steps.0.path', '/contact')
            ->call('save');

        Livewire::actingAs($admin)->test(Index::class)->call('toggle', $workflow->id)->call('runNow', $workflow->id);

        $this->assertSame(['created', 'switched_on', 'updated', 'switched_off', 'run_requested'], $this->types($workflow));
        $this->assertSame('Changed the steps and the schedule.', WorkflowEvent::query()->where('type', 'updated')->value('message'));
        $this->assertSame([$admin->id], WorkflowEvent::query()->distinct()->pluck('user_id')->all());
    }

    public function test_a_deleted_workflows_lines_stay_with_its_name(): void
    {
        $workflow = Workflow::factory()->create(['name' => 'Old form']);
        WorkflowRun::factory()->for($workflow)->create();
        $workflow->toggleActive();

        Livewire::actingAs(User::factory()->admin()->create())->test(Show::class, ['workflow' => $workflow])->call('delete');

        $this->assertSame(['switched_on', 'deleted'], $this->types());
        $this->assertSame([null], WorkflowEvent::query()->distinct()->pluck('workflow_id')->all());
        $this->assertSame(['Old form'], WorkflowEvent::query()->distinct()->pluck('workflow_name')->all());
        $this->assertStringContainsString('with its 1 run(s)', WorkflowEvent::query()->where('type', 'deleted')->value('message'));
    }

    public function test_an_import_is_logged_with_the_file_it_came_from(): void
    {
        Transfer::import(json_encode(Transfer::export(Workflow::factory()->count(1)->make())), source: '01-lets-talk.json');

        $event = WorkflowEvent::query()->sole();
        $this->assertSame(WorkflowEventType::Imported, $event->type);
        $this->assertSame('Imported from 01-lets-talk.json, switched off.', $event->message);
    }

    public function test_a_run_cut_off_is_logged(): void
    {
        Process::fake();
        $run = WorkflowRun::factory()->create(['status' => SimulationStatus::Running, 'started_at' => now()->subHour(), 'finished_at' => null]);

        $this->artisan('tests:workflows')->assertSuccessful();

        $event = WorkflowEvent::query()->sole();
        $this->assertSame(WorkflowEventType::RunStopped, $event->type);
        $this->assertSame($run->id, $event->workflow_run_id);
    }

    public function test_a_browser_that_never_ran_is_logged_as_this_machines_fault(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'Executable doesn\'t exist at chromium', exitCode: 1)]);
        $workflow = Workflow::factory()->active()->create();

        $this->artisan('tests:workflows')->assertSuccessful();

        $this->assertSame(['run_failed', 'could_not_run'], $this->types($workflow));
        $this->assertStringContainsString('this machine — not the site', WorkflowEvent::query()->where('type', 'could_not_run')->value('message'));
    }

    public function test_the_schedule_coming_back_after_a_gap_is_logged_once(): void
    {
        Process::fake();
        $this->freezeSecond();
        Workflow::factory()->create(['active' => true, 'next_run_at' => now()->addHour()]);
        Setting::set(RunWorkflows::LAST_TICK, now()->subHours(3)->subMinutes(10)->toIso8601String());

        $this->artisan('tests:workflows')->assertSuccessful();
        $this->artisan('tests:workflows')->assertSuccessful();

        $event = WorkflowEvent::query()->sole();
        $this->assertSame(WorkflowEventType::ScheduleResumed, $event->type);
        $this->assertNull($event->workflow_id);
        $this->assertStringContainsString('did not run for 3 hours 10 minutes', $event->message);
    }

    public function test_a_gap_with_nothing_switched_on_is_not_a_problem(): void
    {
        Process::fake();
        Workflow::factory()->create(['active' => false]);
        Setting::set(RunWorkflows::LAST_TICK, now()->subDay()->toIso8601String());

        $this->artisan('tests:workflows')->assertSuccessful();

        $this->assertSame(0, WorkflowEvent::query()->count());
    }

    public function test_old_lines_are_pruned_after_event_days_and_outlive_their_runs(): void
    {
        Process::fake();
        config(['workflows.prune_days' => 90, 'workflows.event_days' => 365]);
        $workflow = Workflow::factory()->create();
        $run = WorkflowRun::factory()->for($workflow)->create(['created_at' => now()->subDays(100)]);
        $kept = WorkflowEvent::query()->create(['type' => WorkflowEventType::RunPassed, 'workflow_id' => $workflow->id, 'workflow_run_id' => $run->id, 'message' => 'kept', 'created_at' => now()->subDays(100)]);
        WorkflowEvent::query()->create(['type' => WorkflowEventType::RunPassed, 'workflow_id' => $workflow->id, 'message' => 'gone', 'created_at' => now()->subDays(400)]);

        $this->artisan('tests:workflows')->assertSuccessful();

        $this->assertModelMissing($run);
        $this->assertSame(['kept'], WorkflowEvent::query()->pluck('message')->all());
        $this->assertNull($kept->refresh()->workflow_run_id);
    }
}
