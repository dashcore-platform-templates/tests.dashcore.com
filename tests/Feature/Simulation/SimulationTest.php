<?php

namespace Tests\Feature\Simulation;

use App\Enums\SimulationStatus;
use App\Models\SimulationRun;
use App\Simulation\Journeys\Sales\CaptureLead;
use App\Simulation\Journeys\Sales\CloseWonDeal;
use App\Simulation\Simulation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;

class SimulationTest extends TestCase
{
    use RefreshDatabase;

    private Simulation $simulation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->simulation = app(Simulation::class);
    }

    public function test_it_is_off_until_turned_on_and_remembers_since_when(): void
    {
        $this->assertFalse($this->simulation->isOn());
        $this->assertNull($this->simulation->onSince());

        $this->travelTo(now()->startOfMinute());
        $this->simulation->turnOn();
        $this->travel(5)->minutes();
        $this->simulation->turnOn();

        $this->assertTrue($this->simulation->isOn());
        $this->assertTrue($this->simulation->onSince()->eq(now()->subMinutes(5)));

        $this->simulation->turnOff();

        $this->assertFalse($this->simulation->isOn());
        $this->assertNull($this->simulation->onSince());
    }

    public function test_a_worker_is_alive_only_while_its_heartbeat_is_fresh(): void
    {
        $this->assertFalse($this->simulation->workerAlive());

        $this->simulation->heartbeat();
        $this->assertTrue($this->simulation->workerAlive());

        $this->travel(46)->seconds();
        $this->assertFalse($this->simulation->workerAlive());
    }

    public function test_it_picks_a_named_journey_with_a_persona_from_its_target(): void
    {
        [$journey, $persona] = $this->simulation->pick('sales.close-won-deal');

        $this->assertInstanceOf(CloseWonDeal::class, $journey);
        $this->assertContains($persona->email, array_column(config('simulation.targets.sales.personas'), 'email'));
        $this->assertSame(config('simulation.targets.sales.password'), $persona->password);
    }

    public function test_an_unknown_journey_is_refused_by_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No journey "sales.nope"');

        $this->simulation->pick('sales.nope');
    }

    public function test_draws_follow_the_weights_and_reach_every_persona(): void
    {
        config(['simulation.journeys' => [CaptureLead::class => 1, CloseWonDeal::class => 0]]);

        $people = [];

        for ($i = 0; $i < 60; $i++) {
            [$journey, $persona] = $this->simulation->pick();
            $this->assertInstanceOf(CaptureLead::class, $journey);
            $people[$persona->name] = true;
        }

        $this->assertEqualsCanonicalizing(array_column(config('simulation.targets.sales.personas'), 'name'), array_keys($people));
    }

    public function test_runs_a_dead_worker_left_running_are_marked_stopped(): void
    {
        $running = SimulationRun::factory()->running(3)->create();
        $done = SimulationRun::factory()->create();

        $this->assertSame(1, $this->simulation->recoverInterrupted());

        $this->assertSame(SimulationStatus::Stopped, $running->fresh()->status);
        $this->assertNotNull($running->fresh()->finished_at);
        $this->assertSame(SimulationStatus::Passed, $done->fresh()->status);
    }

    public function test_pruning_keeps_pictures_for_the_newest_runs_and_records_for_keep_days(): void
    {
        $root = sys_get_temp_dir().'/pg-'.bin2hex(random_bytes(4));
        config(['simulation.screenshots' => $root, 'simulation.keep_runs' => 2, 'simulation.keep_days' => 14]);

        $ancient = SimulationRun::factory()->create(['created_at' => now()->subDays(15)]);
        [$old, $newer, $newest] = SimulationRun::factory()->count(3)->create()->all();

        foreach ([$ancient, $old, $newer, $newest] as $run) {
            File::ensureDirectoryExists($this->simulation->screenshotDirectory($run));
        }

        $this->simulation->prune();

        $this->assertModelMissing($ancient);
        $this->assertDirectoryDoesNotExist($this->simulation->screenshotDirectory($ancient));
        $this->assertTrue($old->fresh()->screenshots_pruned);
        $this->assertDirectoryDoesNotExist($this->simulation->screenshotDirectory($old));
        $this->assertFalse($newer->fresh()->screenshots_pruned);
        $this->assertDirectoryExists($this->simulation->screenshotDirectory($newest));

        File::deleteDirectory($root);
    }
}
