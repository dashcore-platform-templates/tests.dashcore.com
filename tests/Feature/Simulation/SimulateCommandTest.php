<?php

namespace Tests\Feature\Simulation;

use App\Console\Commands\Simulate;
use App\Enums\SimulationStatus;
use App\Models\SimulationRun;
use App\Simulation\Simulation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class SimulateCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $shots;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shots = sys_get_temp_dir().'/pg-'.bin2hex(random_bytes(4));
        config(['simulation.screenshots' => $this->shots]);
        Sleep::fake();
        Process::fake(['*' => Process::result(output: json_encode(['event' => 'finished', 'status' => 'failed']))]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->shots);

        parent::tearDown();
    }

    public function test_once_runs_the_named_journey_even_while_switched_off(): void
    {
        $this->artisan('tests:simulate', ['--once' => true, '--journey' => 'sales.close-won-deal'])->assertSuccessful();

        $this->assertSame(['sales.close-won-deal'], SimulationRun::query()->pluck('journey')->all());
        $this->assertFalse(app(Simulation::class)->isOn());
        $this->assertTrue(app(Simulation::class)->workerAlive());
    }

    public function test_while_on_it_runs_journey_after_journey_until_its_run_limit(): void
    {
        app(Simulation::class)->turnOn();

        $this->artisan('tests:simulate', ['--max-runs' => 3])->assertSuccessful();

        $this->assertSame(3, SimulationRun::count());
        $this->assertSame(0, SimulationRun::query()->where('status', 'running')->count());
        Process::assertRanTimes(fn () => true, 3);
    }

    public function test_it_keeps_as_many_journeys_in_flight_as_people_are_set_to_work(): void
    {
        config(['simulation.pause' => 0]);
        Process::fake(['*' => Process::describe()->output(json_encode(['event' => 'finished', 'status' => 'failed']))->iterations(40)]);
        app(Simulation::class)->turnOn();
        app(Simulation::class)->setConcurrency(3);

        $this->artisan('tests:simulate', ['--max-runs' => 3])->assertSuccessful();

        $runs = SimulationRun::query()->orderBy('id')->get();
        $this->assertCount(3, $runs);
        // Each one started before the first had finished.
        $this->assertTrue($runs[2]->started_at->lte($runs[0]->finished_at));
    }

    public function test_switched_off_it_starts_nothing_new(): void
    {
        app(Simulation::class)->turnOff();
        $worker = new Simulate;

        Sleep::whenFakingSleep(function () use ($worker) {
            $worker->handleSignal(SIGTERM);
        });

        $this->app->instance(Simulate::class, $worker);
        $this->artisan('tests:simulate')->assertSuccessful();

        $this->assertSame(0, SimulationRun::count());
        Process::assertNothingRan();
    }

    public function test_it_marks_runs_left_by_a_dead_worker_stopped_before_starting(): void
    {
        $orphan = SimulationRun::factory()->running(4)->create();

        $this->artisan('tests:simulate', ['--once' => true])
            ->expectsOutputToContain('1 run(s) left running by a previous worker marked stopped.')
            ->assertSuccessful();

        $this->assertSame(SimulationStatus::Stopped, $orphan->fresh()->status);
    }
}
