<?php

namespace Tests\Feature\Demo;

use App\Enums\BuildStatus;
use App\Models\Build;
use App\Models\FleetApp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoFleetTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create();
        $this->artisan('tests:demo')->assertSuccessful();
    }

    public function test_the_fleet_lists_three_demo_apps_with_tested_weak_and_missing_surfaces(): void
    {
        $this->actingAs($this->owner)->get(route('fleet.index'))
            ->assertOk()
            ->assertSee(['Demo Sales', 'Demo Finance', 'Demo Website']);

        foreach (FleetApp::query()->where('is_demo', true)->get() as $app) {
            $scan = $app->latestCompletedScan;
            $this->assertGreaterThan(0, $scan->surfaces_tested, $app->key);
            $this->assertGreaterThan(0, $scan->surfaces_missing, $app->key);
            $this->assertSame(3, $app->scans()->count(), $app->key);
        }

        $this->actingAs($this->owner)->get(route('fleet.show', ['key' => 'demo-sales', 'status' => 'all']))
            ->assertOk()
            ->assertSee(['Tested', 'Weak', 'Missing', 'LeadScorer::score', 'Tests still pass when it is broken', 'Lost coverage']);
    }

    public function test_the_latest_scan_shows_a_surviving_mutant_and_a_regression_against_the_one_before(): void
    {
        $scan = FleetApp::query()->where('key', 'demo-sales')->sole()->latestCompletedScan;

        $this->assertSame(1, $scan->mutants_survived);
        $this->assertGreaterThan(0, $scan->surfaces_lost);
        $this->assertGreaterThan(0, $scan->surfaces_new_untested);

        $this->actingAs($this->owner)->get(route('scans.show', $scan))
            ->assertOk()
            ->assertSee('Regressions against scan #'.$scan->previous_scan_id)
            ->assertSee(['Lost coverage', 'leads.board@assign', 'GET /reports/pipeline'])
            ->assertSee('Mutants that survived')
            ->assertSee('LeadScorer::score')
            ->assertSee('Failing tests');
    }

    public function test_a_build_waits_for_review_with_its_pull_request(): void
    {
        $build = Build::sole();

        $this->assertSame(BuildStatus::Opened, $build->status);

        $this->actingAs($this->owner)->get(route('builds.show', $build))
            ->assertOk()
            ->assertSee(['PR open', 'PR #42', 'Proven', 'Not proven', 'deals.convert@win', 'Left out by the agent']);

        $this->actingAs($this->owner)->get(route('fleet.show', 'demo-sales'))
            ->assertOk()
            ->assertSee('In build #'.$build->id);
    }
}
