<?php

namespace Tests\Feature\Simulation;

use App\Enums\ExplorationStatus;
use App\Enums\JourneyStatus;
use App\Jobs\RunExploration;
use App\Models\SimulationExploration;
use App\Models\SimulationJourney;
use App\Simulation\Exploring\Explorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ExplorerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['simulation.targets.sales.checkout' => base_path()]);
    }

    private function exploration(?string $focus = null): SimulationExploration
    {
        return SimulationExploration::create(['target' => 'sales', 'focus' => $focus, 'status' => ExplorationStatus::Queued]);
    }

    /**
     * @param  list<array<string, mixed>>  $journeys
     */
    private function answer(array $journeys, string $notes = 'Deal notes had no journey.'): string
    {
        return json_encode([
            'type' => 'result',
            'is_error' => false,
            'result' => 'Done.',
            'total_cost_usd' => 0.8123,
            'structured_output' => ['journeys' => $journeys, 'notes' => $notes],
        ]);
    }

    private function good(string $key = 'save-deal-notes'): array
    {
        return [
            'key' => $key,
            'name' => 'Write notes on a deal',
            'why' => 'deals@saveDeal with notes is never reached live.',
            'roles' => ['Sales rep'],
            'weight' => 2,
            'summary' => '[[who]] wrote notes on {deal}',
            'steps' => [
                ['do' => 'visit', 'label' => '[[who]] opens the deals', 'path' => '/deals'],
                ['do' => 'remember', 'label' => '[[who]] picks one', 'target' => 'tbody tr:first-child td:first-child button', 'as' => 'deal'],
                ['do' => 'click', 'label' => '[[who]] opens {deal}', 'target' => 'tbody tr:first-child button:has-text("Edit")'],
                ['do' => 'fill', 'label' => '[[who]] writes notes', 'target' => '#editNotes', 'value' => '[[sentence]]'],
                ['do' => 'click', 'label' => '[[who]] saves', 'target' => 'form[wire\:submit\.prevent="saveDeal"] button[type=submit]'],
                ['do' => 'see', 'label' => '[[who]] sees it saved', 'text' => 'Deal saved.'],
            ],
        ];
    }

    public function test_sound_drafts_go_on_trial_and_the_rest_are_turned_away_with_reasons(): void
    {
        Process::fake(['*' => Process::result(output: $this->answer([
            $this->good(),
            [...$this->good('send-a-proposal'), 'steps' => [['do' => 'visit', 'label' => 'x', 'path' => 'https://quo.example']]],
        ]))]);

        $exploration = app(Explorer::class)->explore($this->exploration('deal notes'));

        $this->assertSame(ExplorationStatus::Completed, $exploration->status);
        $this->assertSame([1, 1, '0.8123'], [$exploration->drafted, $exploration->rejected, $exploration->cost_usd]);
        $this->assertStringContainsString('Deal notes had no journey.', $exploration->report);
        $this->assertStringContainsString('Turned away:', $exploration->report);

        $journey = SimulationJourney::sole();
        $this->assertSame(['sales.save-deal-notes', JourneyStatus::Trial, 2, $exploration->id], [$journey->key, $journey->status, $journey->weight, $journey->exploration_id]);
    }

    public function test_the_agent_can_only_read_the_apps_source_within_a_budget(): void
    {
        config(['simulation.explore.budget_usd' => 2.5, 'simulation.explore.model' => 'claude-opus-5-5']);
        Process::fake(['*' => Process::result(output: $this->answer([]))]);

        app(Explorer::class)->explore($this->exploration('the today page'));

        Process::assertRan(function (PendingProcess $process) {
            $command = $process->command;
            $flag = fn (string $name) => $command[array_search($name, $command, true) + 1] ?? null;

            return $process->path === base_path()
                && $flag('--allowedTools') === 'Read,Glob,Grep'
                && str_contains($flag('--disallowedTools'), 'Bash')
                && str_contains($flag('--disallowedTools'), 'Write')
                && in_array('--strict-mcp-config', $command, true)
                && $flag('--max-budget-usd') === '2.5'
                && $flag('--model') === 'claude-opus-5-5'
                && json_decode($flag('--json-schema'), true)['required'] === ['journeys']
                && str_contains($flag('-p'), 'focus on: the today page');
        });
    }

    public function test_an_agent_that_fails_fails_the_exploration_with_its_words(): void
    {
        Process::fake(['*' => Process::result(output: json_encode(['is_error' => true, 'result' => 'Exceeded the budget.']), exitCode: 1)]);

        $exploration = app(Explorer::class)->explore($this->exploration());

        $this->assertSame(ExplorationStatus::Failed, $exploration->status);
        $this->assertStringContainsString('Exceeded the budget.', $exploration->error);
        $this->assertSame(0, SimulationJourney::count());
    }

    public function test_with_no_checkout_to_read_it_says_so(): void
    {
        config(['simulation.targets.sales.checkout' => '/nowhere/sales.fakecompany.com']);
        Process::fake();

        $exploration = app(Explorer::class)->explore($this->exploration());

        $this->assertSame(ExplorationStatus::Failed, $exploration->status);
        $this->assertStringContainsString('No checkout of the sales app to read', $exploration->error);
        Process::assertNothingRan();
    }

    public function test_the_command_queues_an_exploration_or_runs_it_now(): void
    {
        Queue::fake();

        $this->artisan('tests:explore', ['target' => 'sales', '--focus' => 'tasks'])->assertSuccessful();
        Queue::assertPushed(RunExploration::class, fn (RunExploration $job) => $job->exploration->focus === 'tasks');

        Process::fake(['*' => Process::result(output: $this->answer([$this->good()]))]);
        $this->artisan('tests:explore', ['target' => 'sales', '--now' => true])
            ->expectsOutputToContain('1 journey(s) drafted and on trial, 0 turned away, $0.81.')
            ->assertSuccessful();

        $this->artisan('tests:explore', ['target' => 'nope'])->assertFailed();
    }
}
