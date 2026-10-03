<?php

namespace Tests\Feature\Simulation;

use App\Enums\FindingKind;
use App\Enums\FindingStatus;
use App\Enums\SimulationStatus;
use App\Livewire\Simulation\ProvingGround;
use App\Models\SimulationFinding;
use App\Models\SimulationRun;
use App\Models\SimulationStep;
use App\Models\User;
use App\Simulation\Pulse\Pulse;
use App\Simulation\Simulation;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

class ProvingGroundPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_log_in(): void
    {
        $this->get(route('simulation.index'))->assertRedirect(route('login'));
    }

    public function test_every_role_can_watch_and_it_is_in_the_navigation(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('simulation.index'))
            ->assertOk()
            ->assertSeeLivewire(ProvingGround::class)
            ->assertSee('Nothing has run yet.')
            ->assertSee(route('simulation.index'));
    }

    public function test_an_admin_turns_it_on_and_off(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ProvingGround::class)
            ->assertSee('Off')
            ->call('toggle')
            ->assertSee('Waiting for a worker')
            ->assertSee('The switch is on, but no worker is running');

        $this->assertTrue(app(Simulation::class)->isOn());

        Livewire::test(ProvingGround::class)->call('toggle');

        $this->assertFalse(app(Simulation::class)->isOn());
    }

    public function test_a_member_can_watch_but_not_flip_the_switch(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ProvingGround::class)
            ->assertDontSeeHtml('role="switch"')
            ->call('toggle')
            ->assertForbidden();

        $this->assertFalse(app(Simulation::class)->isOn());
    }

    public function test_with_a_live_worker_it_shows_running(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        app(Simulation::class)->turnOn();
        app(Simulation::class)->heartbeat();

        Livewire::test(ProvingGround::class)
            ->assertSee('Running')
            ->assertDontSee('no worker is running');
    }

    public function test_the_live_run_is_on_screen_with_its_steps_and_newest_picture(): void
    {
        $this->actingAs(User::factory()->create());

        SimulationRun::factory()->create(['summary' => 'Maya added lead Old News']);
        $live = SimulationRun::factory()->running(1)->create(['persona_name' => 'Priya Natarajan', 'journey_name' => 'Lead to won deal', 'steps_total' => 3]);
        SimulationStep::factory()->for($live, 'run')->create(['position' => 0, 'label' => 'Priya opens the login page', 'screenshot' => '000.jpg']);
        SimulationStep::factory()->for($live, 'run')->create(['position' => 1, 'label' => 'Priya enters their email', 'screenshot' => '001.jpg']);
        SimulationStep::factory()->for($live, 'run')->create(['position' => 2, 'label' => 'Priya signs in', 'status' => SimulationStatus::Pending, 'screenshot' => null]);

        Livewire::test(ProvingGround::class)
            ->assertSee('Priya Natarajan · Lead to won deal')
            ->assertSee('LIVE')
            ->assertSee('Priya signs in')
            ->assertSeeHtml(route('simulation.screenshot', ['run' => $live->id, 'file' => '001.jpg']))
            ->assertSee('Maya added lead Old News');
    }

    public function test_a_failed_run_shows_why_in_the_feed_and_opens_on_the_step_that_failed(): void
    {
        $this->actingAs(User::factory()->create());

        $run = SimulationRun::factory()->failed('Maya saves the lead: Timeout 15000ms exceeded.')->create();
        SimulationStep::factory()->for($run, 'run')->create(['position' => 0, 'screenshot' => '000.jpg']);
        SimulationStep::factory()->for($run, 'run')->create(['position' => 1, 'label' => 'Maya saves the lead', 'status' => SimulationStatus::Failed, 'screenshot' => '001.jpg', 'error' => 'Timeout 15000ms exceeded.', 'console' => [['level' => 'http', 'text' => '500 POST /livewire/update']]]);
        SimulationStep::factory()->for($run, 'run')->create(['position' => 2, 'label' => 'Maya sees the lead was added', 'status' => SimulationStatus::Skipped, 'screenshot' => null]);

        Livewire::test(ProvingGround::class)
            ->assertSeeText("Maya couldn't finish “Capture a lead”: Maya saves the lead: Timeout 15000ms exceeded.", false)
            ->assertSeeHtml(route('simulation.screenshot', ['run' => $run->id, 'file' => '001.jpg']))
            ->assertSee('500 POST /livewire/update');
    }

    public function test_picking_a_run_replays_it_until_back_to_live(): void
    {
        $this->actingAs(User::factory()->create());

        $old = SimulationRun::factory()->create(['persona_name' => 'Daniel Reyes', 'journey_name' => 'Capture a lead']);
        SimulationStep::factory()->for($old, 'run')->create(['position' => 0, 'label' => 'Daniel opens the login page', 'screenshot' => '000.jpg']);
        SimulationStep::factory()->for($old, 'run')->create(['position' => 1, 'label' => 'Daniel signs in', 'screenshot' => '001.jpg']);
        SimulationRun::factory()->running()->create(['persona_name' => 'Priya Natarajan', 'journey_name' => 'Lead to won deal']);

        Livewire::test(ProvingGround::class)
            ->assertSee('Priya Natarajan · Lead to won deal')
            ->call('watch', $old->id)
            ->assertSee("Replaying run #{$old->id}")
            ->assertSee('Daniel Reyes · Capture a lead')
            ->assertSeeHtml(route('simulation.screenshot', ['run' => $old->id, 'file' => '001.jpg']))
            ->call('showStep', 0)
            ->assertSeeHtml(route('simulation.screenshot', ['run' => $old->id, 'file' => '000.jpg']))
            ->call('follow')
            ->assertSee('Priya Natarajan · Lead to won deal')
            ->assertDontSee('Replaying');
    }

    public function test_totals_count_runs_since_the_switch_went_on(): void
    {
        $this->actingAs(User::factory()->create());

        SimulationRun::factory()->create(['created_at' => now()->subHours(2)]);
        app(Simulation::class)->turnOn();
        $this->travel(1)->second();
        SimulationRun::factory()->count(3)->create(['duration_ms' => 6000]);
        SimulationRun::factory()->failed()->create(['duration_ms' => 2000]);

        $totals = Livewire::test(ProvingGround::class)->instance()->totals;

        $this->assertSame(['label' => 'since switched on', 'runs' => 4, 'passed' => 3, 'failed' => 1, 'rate' => 75.0, 'avg_seconds' => 5.0], $totals);
    }

    public function test_screenshots_are_served_to_people_who_can_watch(): void
    {
        $root = sys_get_temp_dir().'/pg-'.bin2hex(random_bytes(4));
        config(['simulation.screenshots' => $root]);
        $run = SimulationRun::factory()->create();
        File::ensureDirectoryExists("{$root}/{$run->id}");
        File::put("{$root}/{$run->id}/000.jpg", 'jpeg bytes');

        $this->get(route('simulation.screenshot', [$run, '000.jpg']))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create());
        $this->get(route('simulation.screenshot', [$run, '000.jpg']))->assertOk();
        $this->get(route('simulation.screenshot', [$run, '001.jpg']))->assertNotFound();
        $this->get("/proving-ground/runs/{$run->id}/..%2F..%2F.env")->assertNotFound();

        $run->update(['screenshots_pruned' => true]);
        $this->get(route('simulation.screenshot', [$run, '000.jpg']))->assertNotFound();

        File::deleteDirectory($root);
    }

    public function test_open_findings_are_listed_worst_first_with_the_runs_that_hit_them_marked(): void
    {
        $this->actingAs(User::factory()->create());

        $run = SimulationRun::factory()->create(['summary' => 'Maya won a $3,400 deal with Gerlach Ltd', 'findings_count' => 2]);
        SimulationFinding::factory()->create(['kind' => FindingKind::Slow, 'title' => 'Slow when someone saves the lead']);
        SimulationFinding::factory()->create(['kind' => FindingKind::Breaks, 'title' => '“Lead to won deal” breaks when someone creates the deal', 'last_run_id' => $run->id]);
        SimulationFinding::factory()->create(['kind' => FindingKind::DeadSpace, 'title' => 'Resolved one', 'status' => FindingStatus::Resolved]);

        Livewire::test(ProvingGround::class)
            ->assertSeeInOrder(['breaks when someone creates the deal', 'Slow when someone saves the lead'])
            ->assertDontSee('Resolved one')
            ->assertSee('2 findings')
            ->call('showFindings', 'resolved')
            ->assertSee('Resolved one')
            ->assertDontSee('Slow when someone saves the lead');
    }

    public function test_the_open_findings_card_calls_out_ones_that_came_back(): void
    {
        $this->actingAs(User::factory()->create());

        SimulationFinding::factory()->create(['reopened_at' => now()]);
        SimulationFinding::factory()->create();

        $counts = Livewire::test(ProvingGround::class)->assertSee('1 came back after a fix')->instance()->findingCounts;

        $this->assertSame(['open' => 2, 'resolved' => 0, 'ignored' => 0, 'regressions' => 1], $counts);
    }

    public function test_replaying_a_finding_opens_its_last_run_on_the_step_where_it_happened(): void
    {
        $this->actingAs(User::factory()->create());

        $run = SimulationRun::factory()->create();
        SimulationStep::factory()->for($run, 'run')->create(['position' => 0, 'screenshot' => '000.jpg']);
        SimulationStep::factory()->for($run, 'run')->create(['position' => 1, 'screenshot' => '001.jpg', 'label' => 'Maya marks the deal won']);
        SimulationStep::factory()->for($run, 'run')->create(['position' => 2, 'screenshot' => '002.jpg']);
        $finding = SimulationFinding::factory()->create(['last_run_id' => $run->id, 'last_position' => 1]);
        SimulationRun::factory()->running()->create();

        Livewire::test(ProvingGround::class)
            ->call('replayFinding', $finding->id)
            ->assertSet('runId', $run->id)
            ->assertSet('stepPosition', 1)
            ->assertSee("Replaying run #{$run->id}")
            ->assertSeeHtml(route('simulation.screenshot', ['run' => $run->id, 'file' => '001.jpg']));
    }

    public function test_an_admin_ignores_and_reopens_a_finding_and_a_member_cannot(): void
    {
        $finding = SimulationFinding::factory()->create(['clean_runs' => 2]);

        $this->actingAs(User::factory()->create());
        Livewire::test(ProvingGround::class)->call('ignoreFinding', $finding->id)->assertForbidden();
        $this->assertSame(FindingStatus::Open, $finding->fresh()->status);

        $this->actingAs(User::factory()->admin()->create());
        Livewire::test(ProvingGround::class)
            ->call('ignoreFinding', $finding->id)
            ->assertDontSee($finding->title)
            ->call('showFindings', 'ignored')
            ->assertSee($finding->title)
            ->call('reopenFinding', $finding->id);

        $this->assertSame([FindingStatus::Open, 0], [$finding->fresh()->status, $finding->fresh()->clean_runs]);
    }

    public function test_steps_with_no_loading_feedback_are_marked_in_the_step_list(): void
    {
        $this->actingAs(User::factory()->create());

        $run = SimulationRun::factory()->create();
        SimulationStep::factory()->for($run, 'run')->create(['position' => 0, 'label' => 'Maya marks the deal won', 'feedback' => false, 'livewire_requests' => 1]);

        Livewire::test(ProvingGround::class)->assertSee('no feedback');
    }

    public function test_the_business_pulse_shows_each_apps_numbers_or_why_it_could_not_read_them(): void
    {
        $this->actingAs(User::factory()->create());

        $this->app->bind('test.pulse', fn () => new class implements Pulse
        {
            public function read(ConnectionInterface $db, Carbon $now, ?Carbon $since): array
            {
                $db->select('select 1');

                return [['label' => 'Open pipeline', 'value' => '$574,500', 'hint' => '65 open deals']];
            }
        });

        config([
            'simulation.targets' => [
                'sales' => ['name' => 'Sales', 'pulse' => 'test.pulse', 'database' => config('database.connections.pgsql.database')],
                'finance' => ['name' => 'Finance', 'pulse' => 'test.pulse', 'database' => 'no_such_database_here'],
            ],
        ]);

        Livewire::test(ProvingGround::class)
            ->assertSee('Business pulse · Sales')
            ->assertSee(['Open pipeline', '$574,500', '65 open deals'])
            ->assertSee('Could not read Finance');
    }
}
