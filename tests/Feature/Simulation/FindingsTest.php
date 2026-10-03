<?php

namespace Tests\Feature\Simulation;

use App\Enums\FindingKind;
use App\Enums\FindingStatus;
use App\Enums\SimulationStatus;
use App\Models\SimulationFinding;
use App\Models\SimulationRun;
use App\Models\SimulationStep;
use App\Simulation\Findings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FindingsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A run of "Lead to won deal" by Maya whose steps are given as
     * [position => attributes]; unspecified steps pass.
     *
     * @param  array<int, array<string, mixed>>  $steps
     */
    private function simRun(array $steps = [], int $total = 6, string $journey = 'sales.close-won-deal', ?SimulationStatus $status = null): SimulationRun
    {
        $failed = collect($steps)->contains(fn ($step) => ($step['status'] ?? null) === SimulationStatus::Failed);

        $run = SimulationRun::factory()->create([
            'journey' => $journey,
            'journey_name' => $journey === 'sales.close-won-deal' ? 'Lead to won deal' : 'Capture a lead',
            'persona_name' => 'Maya Okafor',
            'status' => $status ?? ($failed ? SimulationStatus::Failed : SimulationStatus::Passed),
            'steps_total' => $total,
        ]);

        for ($position = 0; $position < $total; $position++) {
            SimulationStep::factory()->for($run, 'run')->create([
                'position' => $position,
                'label' => "Maya does step {$position}",
                'duration_ms' => 300,
                ...($steps[$position] ?? []),
            ]);
        }

        return $run;
    }

    private function record(SimulationRun $run): int
    {
        return app(Findings::class)->record($run);
    }

    public function test_a_failed_step_is_a_break_described_for_anyone(): void
    {
        $this->record($this->simRun([3 => ['status' => SimulationStatus::Failed, 'error' => 'Timeout 15000ms exceeded.'], 4 => ['status' => SimulationStatus::Skipped], 5 => ['status' => SimulationStatus::Skipped]]));

        $finding = SimulationFinding::sole();
        $this->assertSame(FindingKind::Breaks, $finding->kind);
        $this->assertSame('“Lead to won deal” breaks when someone does step 3', $finding->title);
        $this->assertSame('Timeout 15000ms exceeded.', $finding->detail);
        $this->assertSame(['sales.close-won-deal', 3, 3], [$finding->journey, $finding->position, $finding->last_position]);
    }

    public function test_a_click_with_no_feedback_is_dead_space_and_an_unjudged_one_is_not(): void
    {
        $run = $this->simRun([
            2 => ['action' => 'click', 'livewire_requests' => 1, 'livewire_ms' => 140, 'feedback' => false],
            3 => ['action' => 'click', 'livewire_requests' => 1, 'livewire_ms' => 900, 'feedback' => null],
            4 => ['action' => 'click', 'livewire_requests' => 1, 'livewire_ms' => 120, 'feedback' => true],
        ]);

        $this->assertSame(1, $this->record($run));

        $finding = SimulationFinding::sole();
        $this->assertSame(FindingKind::DeadSpace, $finding->kind);
        $this->assertSame('Nothing shows it is working when someone does step 2', $finding->title);
        $this->assertStringContainsString('1 request, 140 ms', $finding->detail);
        $this->assertSame(1, $run->fresh()->findings_count);
    }

    public function test_a_passed_step_over_the_limit_is_slow(): void
    {
        config(['simulation.findings.slow_ms' => 3000]);

        $this->record($this->simRun([1 => ['duration_ms' => 4200], 2 => ['duration_ms' => 3000]]));

        $finding = SimulationFinding::sole();
        $this->assertSame(FindingKind::Slow, $finding->kind);
        $this->assertSame('Took 4.2s; the limit is 3.0s.', $finding->detail);
    }

    public function test_a_failed_step_is_not_also_slow(): void
    {
        config(['simulation.findings.slow_ms' => 3000]);

        $this->record($this->simRun([5 => ['status' => SimulationStatus::Failed, 'duration_ms' => 15000]]));

        $this->assertSame([FindingKind::Breaks], SimulationFinding::pluck('kind')->all());
    }

    public function test_server_errors_group_by_request_whatever_the_record(): void
    {
        $this->record($this->simRun([2 => ['console' => [['level' => 'http', 'text' => '500 GET http://sales.test/leads/281']]]]));
        $this->record($this->simRun([2 => ['console' => [['level' => 'http', 'text' => '500 GET http://sales.test/leads/9']]]], journey: 'sales.capture-lead'));

        $finding = SimulationFinding::sole();
        $this->assertSame(FindingKind::ServerError, $finding->kind);
        $this->assertSame('500 from GET /leads/{id}', $finding->title);
        $this->assertSame(2, $finding->occurrences);
        $this->assertNull($finding->journey);
    }

    public function test_browser_errors_group_by_message_and_warnings_are_not_findings(): void
    {
        $this->record($this->simRun([1 => ['console' => [
            ['level' => 'exception', 'text' => 'TypeError: Cannot read properties of null (reading \'id\') at http://sales.test/build/app-123.js:4:17'],
            ['level' => 'warning', 'text' => 'Alpine Warning: something deprecated'],
        ]]]));
        $this->record($this->simRun([1 => ['console' => [
            ['level' => 'exception', 'text' => 'TypeError: Cannot read properties of null (reading \'id\') at http://sales.test/build/app-456.js:9:2'],
        ]]]));

        $finding = SimulationFinding::sole();
        $this->assertSame(FindingKind::ConsoleError, $finding->kind);
        $this->assertSame("TypeError: Cannot read properties of null (reading 'id') at /build/app-#.js:#:#", $finding->title);
        $this->assertSame(2, $finding->occurrences);
    }

    public function test_the_same_problem_twice_in_one_run_is_one_hit(): void
    {
        $this->record($this->simRun([
            1 => ['console' => [['level' => 'error', 'text' => 'Boom'], ['level' => 'error', 'text' => 'Boom']]],
            2 => ['console' => [['level' => 'error', 'text' => 'Boom']]],
        ]));

        $this->assertSame(1, SimulationFinding::sole()->occurrences);
    }

    public function test_a_broken_step_resolves_after_enough_runs_of_that_journey_get_past_it(): void
    {
        config(['simulation.findings.resolve_after' => 2]);
        $this->record($this->simRun([4 => ['status' => SimulationStatus::Failed], 5 => ['status' => SimulationStatus::Skipped]]));
        $finding = SimulationFinding::sole();

        // Stopped before step 4: proves nothing about it.
        $this->record($this->simRun([2 => ['status' => SimulationStatus::Failed], 3 => ['status' => SimulationStatus::Skipped], 4 => ['status' => SimulationStatus::Skipped], 5 => ['status' => SimulationStatus::Skipped]]));
        // Another journey: not the same step.
        $this->record($this->simRun(journey: 'sales.capture-lead'));
        $this->assertSame(0, $finding->fresh()->clean_runs);

        $this->record($this->simRun());
        $this->assertSame([1, FindingStatus::Open], [$finding->fresh()->clean_runs, $finding->fresh()->status]);

        $this->record($this->simRun());
        $this->assertSame(FindingStatus::Resolved, $finding->fresh()->status);
        $this->assertNotNull($finding->fresh()->resolved_at);
    }

    public function test_an_element_with_no_feedback_is_one_finding_whichever_journey_presses_it(): void
    {
        $won = ['action' => 'click', 'target' => 'role=button[name="Won" s]', 'url' => 'http://sales.test/leads/281', 'livewire_requests' => 1, 'feedback' => false];

        $this->record($this->simRun([4 => $won]));
        $this->record($this->simRun([2 => [...$won, 'url' => 'http://sales.test/leads/9']], journey: 'sales.capture-lead'));

        $finding = SimulationFinding::sole();
        $this->assertSame(FindingKind::DeadSpace, $finding->kind);
        $this->assertSame('sales|/leads/{id}|click|role=button[name="Won" s]', $finding->fingerprint);
        $this->assertSame(2, $finding->occurrences);
    }

    public function test_an_element_finding_resolves_when_any_journey_uses_that_element_cleanly(): void
    {
        config(['simulation.findings.resolve_after' => 1]);
        $won = ['action' => 'click', 'target' => 'role=button[name="Won" s]', 'url' => 'http://sales.test/leads/281', 'livewire_requests' => 1];

        $this->record($this->simRun([4 => [...$won, 'feedback' => false]]));
        $finding = SimulationFinding::sole();

        // A run that never pressed it says nothing about it.
        $this->record($this->simRun(journey: 'sales.capture-lead'));
        $this->assertSame(FindingStatus::Open, $finding->fresh()->status);

        $this->record($this->simRun([1 => [...$won, 'feedback' => true]], journey: 'sales.follow-up-lead'));
        $this->assertSame(FindingStatus::Resolved, $finding->fresh()->status);
    }

    public function test_an_app_wide_finding_resolves_on_passed_runs_of_any_journey_on_that_app(): void
    {
        config(['simulation.findings.resolve_after' => 1]);
        $this->record($this->simRun([1 => ['console' => [['level' => 'error', 'text' => 'Boom']]]]));

        $boom = SimulationFinding::sole();

        // A run that failed proves nothing about the app as a whole.
        $this->record($this->simRun([0 => ['status' => SimulationStatus::Failed]], journey: 'sales.capture-lead'));
        $this->assertSame(FindingStatus::Open, $boom->fresh()->status);

        $this->record($this->simRun(journey: 'sales.capture-lead'));
        $this->assertSame(FindingStatus::Resolved, $boom->fresh()->status);
    }

    public function test_a_resolved_finding_that_returns_is_reopened_as_a_regression(): void
    {
        $finding = SimulationFinding::factory()->create([
            'kind' => FindingKind::DeadSpace,
            'fingerprint' => 'sales|/leads/{id}|click|role=button[name="Won" s]',
            'status' => FindingStatus::Resolved,
            'resolved_at' => now()->subDay(),
            'occurrences' => 7,
            'clean_runs' => 3,
        ]);

        $this->record($this->simRun([4 => ['action' => 'click', 'target' => 'role=button[name="Won" s]', 'url' => 'http://sales.test/leads/7', 'livewire_requests' => 1, 'feedback' => false]]));

        $finding->refresh();
        $this->assertSame(FindingStatus::Open, $finding->status);
        $this->assertTrue($finding->isRegression());
        $this->assertNull($finding->resolved_at);
        $this->assertSame([8, 0], [$finding->occurrences, $finding->clean_runs]);
    }

    public function test_an_ignored_finding_keeps_counting_but_stays_ignored(): void
    {
        $finding = SimulationFinding::factory()->create([
            'kind' => FindingKind::DeadSpace,
            'fingerprint' => 'sales|/leads/{id}|click|role=button[name="Won" s]',
            'status' => FindingStatus::Ignored,
        ]);

        $this->record($this->simRun([4 => ['action' => 'click', 'target' => 'role=button[name="Won" s]', 'url' => 'http://sales.test/leads/7', 'livewire_requests' => 1, 'feedback' => false]]));

        $this->assertSame([FindingStatus::Ignored, 2], [$finding->fresh()->status, $finding->fresh()->occurrences]);
    }
}
