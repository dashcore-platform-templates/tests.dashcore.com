<?php

namespace Tests\Feature\Demo;

use App\Enums\FindingStatus;
use App\Enums\SimulationStatus;
use App\Models\SimulationFinding;
use App\Models\SimulationRun;
use App\Models\User;
use App\Simulation\Simulation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesDemoScratch;
use Tests\TestCase;

class DemoProvingGroundTest extends TestCase
{
    use RefreshDatabase, UsesDemoScratch;

    public function test_the_proving_ground_shows_two_runs_their_steps_findings_and_screenshots(): void
    {
        $owner = User::factory()->owner()->create();

        $this->artisan('tests:demo')->assertSuccessful();

        $runs = SimulationRun::query()->where('is_demo', true)->with('steps')->orderBy('id')->get();
        $this->assertSame([SimulationStatus::Passed, SimulationStatus::Failed], $runs->pluck('status')->all());
        $this->assertTrue($runs[1]->steps->contains('status', SimulationStatus::Failed));
        $this->assertTrue($runs[1]->steps->contains('status', SimulationStatus::Passed));
        $this->assertGreaterThan(0, SimulationFinding::query()->where('status', FindingStatus::Open)->count());

        $this->actingAs($owner)->get(route('simulation.index'))
            ->assertOk()
            ->assertSee(['Capture a lead', 'Hand out a lead', 'Timed out after 10s'])
            ->assertSee(['breaks when clicking', 'Nothing shows it is working', 'Slow when clicking']);

        foreach ($runs as $run) {
            foreach ($run->steps->whereNotNull('screenshot') as $step) {
                $this->actingAs($owner)->get($step->screenshotUrl())
                    ->assertOk()
                    ->assertHeader('content-type', 'image/jpeg');
            }
        }

        $directory = app(Simulation::class)->screenshotDirectory($runs[1]);
        $this->assertFileExists($directory.'/003.jpg');

        $this->artisan('tests:demo --wipe')->assertSuccessful();

        $this->assertDirectoryDoesNotExist($directory);
        $this->assertDirectoryDoesNotExist(app(Simulation::class)->screenshotDirectory($runs[0]));
        $this->assertSame(0, SimulationRun::count());
        $this->assertSame(0, SimulationFinding::count());
    }

    public function test_wipe_keeps_real_runs_and_their_screenshots(): void
    {
        $real = SimulationRun::factory()->create();
        $this->artisan('tests:demo')->assertSuccessful();
        $realShots = app(Simulation::class)->screenshotDirectory($real);
        mkdir($realShots, 0777, true);
        touch($realShots.'/000.jpg');

        $this->artisan('tests:demo --wipe')->assertSuccessful();

        $this->assertSame([$real->id], SimulationRun::pluck('id')->all());
        $this->assertFileExists($realShots.'/000.jpg');
    }
}
