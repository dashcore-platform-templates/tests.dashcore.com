<?php

namespace Tests\Feature\Simulation;

use App\Enums\SimulationStatus;
use App\Models\SimulationRun;
use App\Models\SimulationStep;
use App\Simulation\Journeys\Finance\RecordExpense;
use App\Simulation\Journeys\Sales\CaptureLead;
use App\Simulation\Journeys\Sales\CloseWonDeal;
use App\Simulation\Persona;
use App\Simulation\Pulse\Pulse;
use App\Simulation\Simulation;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

        $reps = collect(config('simulation.targets.sales.personas'))->where('role', 'Sales rep')->pluck('name')->all();
        $this->assertEqualsCanonicalizing($reps, array_keys($people));
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

    public function test_a_journey_is_walked_only_by_personas_in_its_roles(): void
    {
        for ($i = 0; $i < 20; $i++) {
            [, $persona] = $this->simulation->pick('sales.assign-lead');
            $this->assertSame('Owner', $persona->role);

            [, $persona] = $this->simulation->pick('sales.capture-lead');
            $this->assertSame('Sales rep', $persona->role);
        }
    }

    public function test_a_role_nobody_has_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has no personas in the role Auditor');

        $this->simulation->persona('sales', ['Auditor']);
    }

    public function test_the_pulse_reads_each_target_in_its_own_timezone_and_reports_one_it_cannot_read(): void
    {
        $this->travelTo(Carbon::parse('2026-10-03 04:00:00', 'UTC'));
        config([
            'simulation.targets.sales.pulse' => FakePulse::class,
            'simulation.targets.sales.database' => config('database.connections.pgsql.database'),
            'simulation.targets.sales.timezone' => 'America/Los_Angeles',
            'simulation.targets.broken' => ['name' => 'Broken', 'pulse' => FakePulse::class, 'database' => 'no_such_database_here'],
        ]);

        $pulse = $this->simulation->pulse();

        $this->assertSame([['label' => 'Today starts', 'value' => '2026-10-02 00:00:00', 'hint' => 'America/Los_Angeles']], $pulse['sales']['metrics']);
        $this->assertNull($pulse['sales']['error']);
        $this->assertSame('Broken', $pulse['broken']['name']);
        $this->assertSame([], $pulse['broken']['metrics']);
        $this->assertNotNull($pulse['broken']['error']);
    }

    public function test_people_at_once_is_kept_between_one_and_the_most_the_machine_takes(): void
    {
        config(['simulation.concurrency' => 2]);
        $this->assertSame(2, $this->simulation->concurrency());

        $this->simulation->setConcurrency(99);
        $this->assertSame(Simulation::MAX_CONCURRENCY, $this->simulation->concurrency());

        $this->simulation->setConcurrency(0);
        $this->assertSame(1, $this->simulation->concurrency());
    }

    public function test_load_compares_the_last_quarter_hour_with_the_first_after_switching_on(): void
    {
        $this->travelTo(now()->startOfMinute());
        $this->simulation->turnOn();

        $step = fn (int $ms, ?int $livewire = null) => SimulationStep::factory()->create(['action' => 'click', 'duration_ms' => $ms, 'livewire_ms' => $livewire]);
        foreach ([100, 200, 300] as $ms) {
            $step($ms, 50);
        }

        $this->travel(40)->minutes();
        foreach ([400, 500, 600, 700] as $ms) {
            $step($ms, 150);
        }
        SimulationRun::factory()->running()->create();

        $load = $this->simulation->load();

        $this->assertSame(1, $load['in_flight']);
        $this->assertSame(550, $load['now']['p50']);
        $this->assertSame(150, $load['now']['livewire_p95']);
        $this->assertSame(200, $load['start']['p50']);
    }

    public function test_a_target_on_another_server_gets_its_own_connection_settings(): void
    {
        config(['simulation.targets.finance.connection' => ['driver' => 'mysql', 'host' => '10.0.0.9', 'port' => '3307', 'username' => 'root', 'password' => '']]);

        $this->simulation->targetDatabase('finance', 'finance_fakecompany_com');

        $connection = config('database.connections.simulation_finance');
        $this->assertSame(['mysql', '10.0.0.9', '3307', 'finance_fakecompany_com'], [$connection['driver'], $connection['host'], $connection['port'], $connection['database']]);
        $this->assertSame(config('database.connections.mysql.charset'), $connection['charset']);
    }

    public function test_each_target_signs_in_through_its_own_login_form(): void
    {
        $persona = new Persona('Omar Haddad', 'omar@example.test', 'secret', 'Bookkeeper');

        $finance = (new RecordExpense)->script($persona, fake())->steps();
        $sales = (new CaptureLead)->script($persona, fake())->steps();

        $this->assertSame(['input[name="email"]', 'input[name="password"]', 'button[data-test="login-button"]', 'Overview'], [$finance[1]['target'], $finance[2]['target'], $finance[3]['target'], $finance[4]['text']]);
        $this->assertSame(['#email', '#password', 'Dashboard'], [$sales[1]['target'], $sales[2]['target'], $sales[4]['text']]);
    }
}

/**
 * Reports the start of the target's day, to show which timezone it was given;
 * touching the database proves the connection works.
 */
class FakePulse implements Pulse
{
    public function read(ConnectionInterface $db, Carbon $now, ?Carbon $since): array
    {
        $db->select('select 1');

        return [['label' => 'Today starts', 'value' => $now->copy()->startOfDay()->format('Y-m-d H:i:s'), 'hint' => $now->timezoneName]];
    }
}
