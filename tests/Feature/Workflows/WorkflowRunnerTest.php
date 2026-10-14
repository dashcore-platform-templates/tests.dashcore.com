<?php

namespace Tests\Feature\Workflows;

use App\Enums\SimulationStatus;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Notifications\WorkflowAlert;
use App\Workflows\Alerts;
use App\Workflows\WorkflowRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class WorkflowRunnerTest extends TestCase
{
    use RefreshDatabase;

    private string $shots;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shots = sys_get_temp_dir().'/wf-'.bin2hex(random_bytes(4));
        config(['workflows.screenshots' => $this->shots, 'workflows.fake_email' => 'monitor+{random}@inbox.test']);
        Notification::fake();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->shots);

        parent::tearDown();
    }

    private function passing(int $steps): void
    {
        $lines = [];

        for ($i = 0; $i < $steps; $i++) {
            $lines[] = json_encode(['event' => 'step', 'position' => $i, 'status' => 'passed', 'ms' => 90, 'url' => 'https://site.example.test/contact', 'screenshot' => sprintf('%03d.jpg', $i)]);
        }

        $lines[] = json_encode(['event' => 'finished', 'status' => 'passed']);
        Process::fake(['*' => Process::result(output: implode("\n", $lines))]);
    }

    private function failingAt(int $position): void
    {
        Process::fake(['*' => Process::result(output: implode("\n", [
            json_encode(['event' => 'step', 'position' => 0, 'status' => 'passed', 'ms' => 90]),
            json_encode(['event' => 'step', 'position' => $position, 'status' => 'failed', 'ms' => 20000, 'screenshot' => '001.jpg', 'error' => 'Timeout 20000ms exceeded waiting for #email']),
            json_encode(['event' => 'finished', 'status' => 'failed']),
        ]))]);
    }

    public function test_a_walk_that_passes_records_every_step_and_the_workflows_result(): void
    {
        $this->passing(4);
        $workflow = Workflow::factory()->create(['consecutive_failures' => 1]);

        $run = app(WorkflowRunner::class)->run($workflow, WorkflowRun::TRIGGER_SCHEDULE);

        $this->assertSame(SimulationStatus::Passed, $run->status);
        $this->assertSame(4, $run->steps_passed);
        $this->assertNull($run->error);
        $this->assertNotNull($run->duration_ms);
        $this->assertSame(['passed'], $run->steps()->pluck('status')->map->value->unique()->values()->all());
        $this->assertSame('003.jpg', $run->steps()->where('position', 3)->value('screenshot'));

        $workflow->refresh();
        $this->assertSame(SimulationStatus::Passed, $workflow->last_status);
        $this->assertSame(0, $workflow->consecutive_failures);
        $this->assertNotNull($workflow->last_run_at);
        Notification::assertNothingSent();
    }

    public function test_the_browser_gets_the_start_url_and_steps_with_fresh_fake_data(): void
    {
        $this->passing(4);
        $workflow = Workflow::factory()->create();

        $run = app(WorkflowRunner::class)->run($workflow, WorkflowRun::TRIGGER_MANUAL);

        Process::assertRan(function (PendingProcess $process) use ($run) {
            $payload = json_decode($process->input, true);

            return $process->command === [config('simulation.node'), config('simulation.runner')]
                && $payload['base_url'] === 'https://site.example.test'
                && $payload['shots_dir'] === $this->shots.'/'.$run->id
                && $payload['steps'][0] === ['do' => 'visit', 'path' => '/contact', 'label' => 'Open the contact page']
                && preg_match('/^monitor\+[a-z0-9]{12}@inbox\.test$/', $payload['steps'][1]['value']) === 1;
        });
        $this->assertSame('manual', $run->trigger);
    }

    public function test_a_failed_step_fails_the_run_and_skips_what_never_ran(): void
    {
        $this->failingAt(1);
        $workflow = Workflow::factory()->create();

        $run = app(WorkflowRunner::class)->run($workflow, WorkflowRun::TRIGGER_SCHEDULE);

        $this->assertSame(SimulationStatus::Failed, $run->status);
        $this->assertSame('Type an email: Timeout 20000ms exceeded waiting for #email', $run->error);
        $this->assertSame(SimulationStatus::Failed, $run->failedStep()->status);
        $this->assertSame(2, $run->steps()->where('status', SimulationStatus::Skipped)->count());
        $this->assertSame(1, $workflow->refresh()->consecutive_failures);
    }

    public function test_a_browser_that_cannot_start_fails_the_run_with_its_own_words(): void
    {
        Process::fake(['*' => Process::result(errorOutput: "Error: browserType.launch: Executable doesn't exist", exitCode: 1)]);

        $run = app(WorkflowRunner::class)->run(Workflow::factory()->create(), WorkflowRun::TRIGGER_SCHEDULE);

        $this->assertSame(SimulationStatus::Failed, $run->status);
        $this->assertStringContainsString("Executable doesn't exist", $run->error);
    }

    public function test_a_browser_that_says_passed_but_skipped_steps_does_not_pass(): void
    {
        Process::fake(['*' => Process::result(output: json_encode(['event' => 'finished', 'status' => 'passed']))]);

        $run = app(WorkflowRunner::class)->run(Workflow::factory()->create(), WorkflowRun::TRIGGER_SCHEDULE);

        $this->assertSame(SimulationStatus::Failed, $run->status);
    }

    public function test_one_failure_stays_quiet_and_the_second_in_a_row_alerts_once(): void
    {
        $workflow = Workflow::factory()->create(['alert_after' => 2, 'notify' => ['owner@example.test', 'ops@example.test']]);
        $runner = app(WorkflowRunner::class);

        $this->failingAt(1);
        $runner->run($workflow, WorkflowRun::TRIGGER_SCHEDULE);
        Notification::assertNothingSent();

        $runner->run($workflow, WorkflowRun::TRIGGER_SCHEDULE);
        Notification::assertSentOnDemand(WorkflowAlert::class, function (WorkflowAlert $alert, array $channels, AnonymousNotifiable $notifiable) {
            return $alert->kind === Alerts::BROKE
                && $notifiable->routes['mail'] === ['owner@example.test', 'ops@example.test']
                && str_contains($alert->toMail($notifiable)->subject, 'Workflow broken');
        });
        $this->assertTrue($workflow->refresh()->alerting);

        // Still broken: no more mail until it recovers.
        $runner->run($workflow, WorkflowRun::TRIGGER_SCHEDULE);
        Notification::assertSentOnDemandTimes(WorkflowAlert::class, 1);
        $this->assertSame(3, $workflow->refresh()->consecutive_failures);
    }

    public function test_a_pass_after_an_alert_says_it_recovered(): void
    {
        $this->passing(4);
        $workflow = Workflow::factory()->create(['alerting' => true, 'consecutive_failures' => 3]);

        app(WorkflowRunner::class)->run($workflow, WorkflowRun::TRIGGER_SCHEDULE);

        Notification::assertSentOnDemand(WorkflowAlert::class, fn (WorkflowAlert $alert) => $alert->kind === Alerts::RECOVERED);
        $this->assertFalse($workflow->refresh()->alerting);
    }

    public function test_nobody_to_notify_means_no_mail(): void
    {
        $this->failingAt(1);
        $workflow = Workflow::factory()->create(['alert_after' => 1, 'notify' => []]);

        app(WorkflowRunner::class)->run($workflow, WorkflowRun::TRIGGER_SCHEDULE);

        Notification::assertNothingSent();
        $this->assertTrue($workflow->refresh()->alerting);
    }
}
