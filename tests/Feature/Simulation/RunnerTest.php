<?php

namespace Tests\Feature\Simulation;

use App\Enums\FindingKind;
use App\Enums\SimulationStatus;
use App\Models\SimulationFinding;
use App\Models\SimulationReach;
use App\Simulation\Journeys\Sales\AssignLead;
use App\Simulation\Journeys\Sales\CaptureLead;
use App\Simulation\Journeys\Sales\FollowUpLead;
use App\Simulation\Persona;
use App\Simulation\Runner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class RunnerTest extends TestCase
{
    use RefreshDatabase;

    private string $shots;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shots = sys_get_temp_dir().'/pg-'.bin2hex(random_bytes(4));
        config(['simulation.screenshots' => $this->shots]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->shots);

        parent::tearDown();
    }

    private function persona(): Persona
    {
        return new Persona('Maya Okafor', 'maya.okafor@example.com', 'secret', 'Sales rep');
    }

    /**
     * @return list<string>
     */
    private function passingOutput(int $steps): array
    {
        $lines = [json_encode(['event' => 'started'])];

        for ($i = 0; $i < $steps; $i++) {
            $lines[] = json_encode(['event' => 'step', 'position' => $i, 'status' => 'running']);
            $lines[] = json_encode(['event' => 'step', 'position' => $i, 'status' => 'passed', 'ms' => 120, 'url' => 'http://sales.test/leads', 'screenshot' => sprintf('%03d.jpg', $i), 'error' => null]);
        }

        $lines[] = json_encode(['event' => 'finished', 'status' => 'passed']);

        return $lines;
    }

    public function test_a_journey_the_browser_walks_end_to_end_passes(): void
    {
        $steps = count((new CaptureLead)->script($this->persona(), fake())->steps());
        Process::fake(['*' => Process::result(output: implode("\n", $this->passingOutput($steps)))]);

        $run = app(Runner::class)->run(new CaptureLead, $this->persona());

        $this->assertSame(SimulationStatus::Passed, $run->status);
        $this->assertSame($steps, $run->steps_total);
        $this->assertSame($steps, $run->steps_passed);
        $this->assertNull($run->error);
        $this->assertNotNull($run->duration_ms);
        $this->assertStringStartsWith('Maya added lead ', $run->summary);
        $this->assertSame(0, $run->steps()->whereNot('status', SimulationStatus::Passed->value)->count());
        $this->assertSame('000.jpg', $run->steps()->first()->screenshot);
        $this->assertDirectoryExists($this->shots.'/'.$run->id);
    }

    public function test_the_browser_is_handed_the_script_with_the_targets_url_and_the_personas_login(): void
    {
        config(['simulation.targets.sales.base_url' => 'http://sales.example.test']);
        Process::fake(['*' => Process::result(output: json_encode(['event' => 'finished', 'status' => 'failed']))]);

        app(Runner::class)->run(new CaptureLead, $this->persona());

        Process::assertRan(function (PendingProcess $process) {
            $payload = json_decode($process->input, true);

            return $process->command === [config('simulation.node'), config('simulation.runner')]
                && $payload['base_url'] === 'http://sales.example.test'
                && str_starts_with($payload['shots_dir'], $this->shots.'/')
                && $payload['steps'][0] === ['do' => 'visit', 'label' => 'Maya opens the login page', 'path' => '/login']
                && collect($payload['steps'])->contains(fn ($step) => ($step['value'] ?? null) === 'maya.okafor@example.com');
        });
    }

    public function test_a_failing_step_fails_the_run_names_the_step_and_skips_the_rest(): void
    {
        Process::fake(['*' => Process::result(output: implode("\n", [
            json_encode(['event' => 'step', 'position' => 0, 'status' => 'passed', 'ms' => 100, 'screenshot' => '000.jpg']),
            json_encode(['event' => 'console', 'position' => 1, 'level' => 'http', 'text' => '500 POST http://sales.test/livewire/update']),
            json_encode(['event' => 'step', 'position' => 1, 'status' => 'failed', 'ms' => 15000, 'screenshot' => '001.jpg', 'error' => 'Timeout 15000ms exceeded.']),
            json_encode(['event' => 'finished', 'status' => 'failed']),
        ]))]);

        $run = app(Runner::class)->run(new CaptureLead, $this->persona());

        $this->assertSame(SimulationStatus::Failed, $run->status);
        $this->assertSame(1, $run->steps_passed);
        $this->assertSame('Maya enters their email: Timeout 15000ms exceeded.', $run->error);

        $failed = $run->steps()->where('position', 1)->first();
        $this->assertSame(SimulationStatus::Failed, $failed->status);
        $this->assertSame([['level' => 'http', 'text' => '500 POST http://sales.test/livewire/update']], $failed->console);
        $this->assertSame(0, $run->steps()->where('position', '>', 1)->whereNot('status', SimulationStatus::Skipped->value)->count());
    }

    public function test_a_browser_that_dies_without_a_verdict_fails_the_run_with_its_error_output(): void
    {
        Process::fake(['*' => Process::result(errorOutput: "Error: browserType.launch: Executable doesn't exist", exitCode: 1)]);

        $run = app(Runner::class)->run(new CaptureLead, $this->persona());

        $this->assertSame(SimulationStatus::Failed, $run->status);
        $this->assertStringContainsString("Executable doesn't exist", $run->error);
        $this->assertSame(0, $run->steps()->whereNot('status', SimulationStatus::Skipped->value)->count());
    }

    public function test_a_finished_verdict_of_passed_does_not_pass_a_run_with_unreported_steps(): void
    {
        Process::fake(['*' => Process::result(output: json_encode(['event' => 'finished', 'status' => 'passed']))]);

        $run = app(Runner::class)->run(new CaptureLead, $this->persona());

        $this->assertSame(SimulationStatus::Failed, $run->status);
        $this->assertSame('The browser stopped without reporting a result.', $run->error);
    }

    public function test_lines_that_are_not_json_are_ignored(): void
    {
        $steps = count((new CaptureLead)->script($this->persona(), fake())->steps());
        Process::fake(['*' => Process::result(output: implode("\n", ['Debugger attached.', ...$this->passingOutput($steps)]))]);

        $this->assertSame(SimulationStatus::Passed, app(Runner::class)->run(new CaptureLead, $this->persona())->status);
    }

    public function test_livewire_timing_and_loading_feedback_are_kept_and_become_findings(): void
    {
        Process::fake(['*' => Process::result(output: implode("\n", [
            json_encode(['event' => 'step', 'position' => 0, 'status' => 'passed', 'ms' => 100, 'livewire_requests' => 0, 'livewire_ms' => null, 'feedback' => null]),
            json_encode(['event' => 'step', 'position' => 1, 'status' => 'passed', 'ms' => 300, 'livewire_requests' => 1, 'livewire_ms' => 140, 'feedback' => false]),
            json_encode(['event' => 'finished', 'status' => 'failed']),
        ]))]);

        $run = app(Runner::class)->run(new CaptureLead, $this->persona());
        $step = $run->steps()->where('position', 1)->first();

        $this->assertSame([1, 140, false], [$step->livewire_requests, $step->livewire_ms, $step->feedback]);
        $this->assertNull($run->steps()->where('position', 0)->first()->feedback);
        $this->assertSame(1, $run->fresh()->findings_count);
        $this->assertSame(FindingKind::DeadSpace, SimulationFinding::sole()->kind);
    }

    public function test_what_a_journey_remembers_names_the_record_in_its_steps_and_summary(): void
    {
        $journey = new FollowUpLead;
        $remember = collect($journey->script($this->persona(), fake())->steps())->search(fn ($step) => $step['do'] === 'remember');

        Process::fake(['*' => Process::result(output: implode("\n", [
            json_encode(['event' => 'remember', 'position' => $remember, 'name' => 'lead', 'value' => 'Krista Streich']),
            json_encode(['event' => 'finished', 'status' => 'failed']),
        ]))]);

        $run = app(Runner::class)->run($journey, $this->persona());

        $this->assertStringContainsString('Krista Streich', $run->summary);
        $this->assertStringNotContainsString('{lead}', $run->summary);
        $this->assertSame(0, $run->steps()->where('label', 'like', '%{lead}%')->count());
        $this->assertGreaterThan(0, $run->steps()->where('label', 'like', '%Krista Streich%')->count());
    }

    public function test_choosing_an_option_by_its_text_keeps_the_steps_own_label(): void
    {
        Process::fake(['*' => Process::result(output: json_encode(['event' => 'finished', 'status' => 'failed']))]);

        app(Runner::class)->run(new AssignLead, new Persona('Admin', 'admin@example.com', 'secret', 'Owner'));

        Process::assertRan(function (PendingProcess $process) {
            $choose = collect(json_decode($process->input, true)['steps'])->firstWhere('do', 'select');

            return str_starts_with($choose['label'], 'Admin gives {lead} to ')
                && in_array($choose['option'], ['Maya Okafor', 'Daniel Reyes', 'Priya Natarajan'], true);
        });
    }

    public function test_what_the_steps_reached_is_kept_on_the_run_and_added_to_the_tally(): void
    {
        Process::fake(['*' => Process::result(output: implode("\n", [
            json_encode(['event' => 'step', 'position' => 0, 'status' => 'passed', 'reached' => ['pages' => ['GET /login'], 'calls' => [], 'mounts' => ['pages.auth.login']]]),
            json_encode(['event' => 'step', 'position' => 1, 'status' => 'passed', 'reached' => ['pages' => ['GET /leads/12'], 'calls' => ['leads.index@createLead', 'leads.index@$commit'], 'mounts' => []]]),
            json_encode(['event' => 'finished', 'status' => 'failed']),
        ]))]);

        $run = app(Runner::class)->run(new CaptureLead, $this->persona());

        $this->assertSame([
            'pages' => ['GET /login', 'GET /leads/{n}'],
            'calls' => ['leads.index@createLead'],
            'mounts' => ['pages.auth.login'],
        ], $run->fresh()->reached);
        $this->assertSame(4, SimulationReach::count());
    }
}
