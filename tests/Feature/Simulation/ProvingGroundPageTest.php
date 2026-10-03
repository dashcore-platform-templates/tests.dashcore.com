<?php

namespace Tests\Feature\Simulation;

use App\Enums\SimulationStatus;
use App\Livewire\Simulation\ProvingGround;
use App\Models\SimulationRun;
use App\Models\SimulationStep;
use App\Models\User;
use App\Simulation\Simulation;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
