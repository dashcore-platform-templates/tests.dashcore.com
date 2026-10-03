<?php

namespace Tests\Feature\Simulation;

use App\Enums\JourneyStatus;
use App\Enums\SimulationStatus;
use App\Models\SimulationJourney;
use App\Models\SimulationRun;
use App\Simulation\Persona;
use App\Simulation\Simulation;
use App\Simulation\StoredJourney;
use App\Simulation\Trials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoredJourneyTest extends TestCase
{
    use RefreshDatabase;

    private function persona(): Persona
    {
        return new Persona('Maya Okafor', 'maya@example.test', 'secret', 'Sales rep');
    }

    public function test_it_signs_in_then_walks_its_steps_with_fresh_fake_data_the_same_all_run(): void
    {
        $record = SimulationJourney::factory()->create([
            'steps' => [
                ['do' => 'visit', 'label' => '[[who]] opens the leads list', 'path' => '/leads'],
                ['do' => 'fill', 'label' => '[[who]] types [[name]]', 'target' => '#newName', 'value' => '[[name]]'],
                ['do' => 'fill', 'label' => '[[who]] sets a date', 'target' => '#due', 'value' => '[[date+3]]'],
                ['do' => 'select', 'label' => '[[who]] picks Priya', 'target' => '#owner', 'option' => 'Priya Natarajan'],
                ['do' => 'click', 'label' => '[[who]] marks it won', 'target' => 'role=button[name="Won"]', 'confirm' => true],
                ['do' => 'remember', 'label' => '[[who]] notes the lead', 'target' => 'h1', 'as' => 'lead'],
                ['do' => 'see', 'label' => '[[who]] sees [[name]] next to {lead}', 'text' => '[[name]]'],
            ],
            'summary' => '[[who]] added [[name]] for {lead}',
        ]);

        $script = (new StoredJourney($record))->script($this->persona(), fake());
        $steps = $script->steps();
        $name = $steps[6]['value'];

        $this->assertSame(['do' => 'visit', 'label' => 'Maya opens the login page', 'path' => '/login'], $steps[0]);
        $this->assertSame('Maya opens the leads list', $steps[5]['label']);
        $this->assertSame("Maya types {$name}", $steps[6]['label']);
        $this->assertSame(now()->addDays(3)->format('Y-m-d'), $steps[7]['value']);
        $this->assertSame('Priya Natarajan', $steps[8]['option']);
        $this->assertTrue($steps[9]['confirm']);
        $this->assertSame(['do' => 'remember', 'label' => 'Maya notes the lead', 'target' => 'h1', 'as' => 'lead'], $steps[10]);
        $this->assertSame($name, $steps[11]['text']);
        $this->assertSame("Maya added {$name} for {lead}", $script->getSummary());
    }

    public function test_trial_and_active_drafts_are_in_the_rotation_and_held_or_retired_ones_are_not(): void
    {
        config(['simulation.trials.weight' => 4]);
        SimulationJourney::factory()->create(['key' => 'sales.on-trial', 'status' => JourneyStatus::Trial, 'weight' => 1]);
        SimulationJourney::factory()->create(['key' => 'sales.proven', 'status' => JourneyStatus::Active, 'weight' => 2]);
        SimulationJourney::factory()->create(['key' => 'sales.held', 'status' => JourneyStatus::Held]);
        SimulationJourney::factory()->create(['key' => 'sales.retired', 'status' => JourneyStatus::Retired]);
        SimulationJourney::factory()->create(['key' => 'nowhere.elsewhere', 'target' => 'nowhere', 'status' => JourneyStatus::Active]);

        $journeys = app(Simulation::class)->journeys();

        $this->assertSame(4, $journeys['sales.on-trial']['weight']);
        $this->assertSame(2, $journeys['sales.proven']['weight']);
        $this->assertInstanceOf(StoredJourney::class, $journeys['sales.proven']['journey']);
        $this->assertArrayNotHasKey('sales.held', $journeys);
        $this->assertArrayNotHasKey('sales.retired', $journeys);
        $this->assertArrayNotHasKey('nowhere.elsewhere', $journeys);
    }

    public function test_a_trial_is_won_by_passes_in_a_row_and_lost_by_failures(): void
    {
        config(['simulation.trials.passes' => 2, 'simulation.trials.failures' => 2]);
        $trials = app(Trials::class);
        $journey = SimulationJourney::factory()->create();
        $pass = fn () => SimulationRun::factory()->create();
        $fail = fn () => SimulationRun::factory()->failed('Maya saves the note: Timeout')->create();

        $trials->afterRun($journey, $pass());
        $trials->afterRun($journey, $fail());
        $this->assertSame([JourneyStatus::Trial, 0, 1], [$journey->status, $journey->trial_passes, $journey->trial_failures]);

        $trials->afterRun($journey, $pass());
        $trials->afterRun($journey, $pass());
        $this->assertSame(JourneyStatus::Active, $journey->fresh()->status);
        $this->assertNotNull($journey->fresh()->activated_at);

        $held = SimulationJourney::factory()->create();
        $trials->afterRun($held, $fail());
        $trials->afterRun($held, $fail());
        $this->assertSame(JourneyStatus::Held, $held->fresh()->status);
        $this->assertSame('Maya saves the note: Timeout', $held->fresh()->last_error);

        $trials->retry($held);
        $this->assertSame([JourneyStatus::Trial, 0, 0, null], [$held->status, $held->trial_passes, $held->trial_failures, $held->last_error]);
    }

    public function test_an_active_journey_failing_stays_active_and_just_records_its_run(): void
    {
        $journey = SimulationJourney::factory()->create(['status' => JourneyStatus::Active]);
        $run = SimulationRun::factory()->failed()->create();

        app(Trials::class)->afterRun($journey, $run);

        $this->assertSame([JourneyStatus::Active, $run->id], [$journey->fresh()->status, $journey->fresh()->last_run_id]);
        $this->assertSame(SimulationStatus::Failed, $run->status);
    }
}
