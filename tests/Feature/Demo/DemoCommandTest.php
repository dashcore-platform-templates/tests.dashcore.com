<?php

namespace Tests\Feature\Demo;

use App\Jobs\RunScan;
use App\Models\Build;
use App\Models\FleetApp;
use App\Models\Scan;
use App\Models\ScanTest;
use App\Models\Surface;
use App\Models\User;
use App\Scanning\FleetSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\UsesFleetFixtures;
use Tests\TestCase;

class DemoCommandTest extends TestCase
{
    use RefreshDatabase, UsesFleetFixtures;

    public function test_it_refuses_in_production_and_changes_nothing(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('tests:demo')
            ->expectsOutputToContain('local use only')
            ->assertFailed();

        $this->artisan('tests:demo --wipe')->assertFailed();

        $this->assertSame(0, FleetApp::withTrashed()->count());
    }

    public function test_running_it_twice_never_doubles_anything_up(): void
    {
        $this->artisan('tests:demo')->assertSuccessful();
        $counts = $this->counts();

        $this->artisan('tests:demo')->assertSuccessful();

        $this->assertSame($counts, $this->counts());
        $this->assertSame(3, FleetApp::query()->where('is_demo', true)->count());
    }

    public function test_wipe_removes_only_demo_rows(): void
    {
        $real = FleetApp::factory()->create(['key' => 'finance']);
        $realScan = $real->scans()->create(['status' => 'completed']);

        $this->artisan('tests:demo')->assertSuccessful();
        $this->assertGreaterThan(1, Scan::count());

        $this->artisan('tests:demo --wipe')->assertSuccessful();

        $this->assertSame(['finance'], FleetApp::withTrashed()->pluck('key')->all());
        $this->assertSame([$realScan->id], Scan::pluck('id')->all());
        $this->assertSame(0, Surface::count());
        $this->assertSame(0, Build::count());
        $this->assertSame(0, ScanTest::count());
    }

    public function test_a_banner_shows_on_every_page_while_demo_data_exists(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get(route('fleet.index'))->assertOk()->assertDontSee('data-demo-banner', false);

        $this->artisan('tests:demo')->assertSuccessful();

        foreach (['dashboard', 'fleet.index', 'library.index', 'simulation.index', 'simulation.journey-map', 'workflows.index', 'workflows.health'] as $route) {
            $this->actingAs($owner)->get(route($route))->assertOk()->assertSee('data-demo-banner', false)->assertSee('tests:demo --wipe');
        }

        $this->artisan('tests:demo --wipe')->assertSuccessful();

        $this->actingAs($owner)->get(route('fleet.index'))->assertOk()->assertDontSee('data-demo-banner', false);
    }

    public function test_the_fleet_sync_leaves_demo_apps_alone(): void
    {
        $this->artisan('tests:demo')->assertSuccessful();
        $this->fakeInstall(['finance' => 'finance.dashcore.com.test']);

        $result = app(FleetSync::class)->sync();

        $this->assertSame([], $result['removed']);
        $this->assertSame(3, FleetApp::query()->where('is_demo', true)->count());
        $this->assertSame(['finance'], FleetApp::query()->real()->pluck('key')->all());
    }

    public function test_nightly_scans_skip_demo_apps_even_with_a_checkout(): void
    {
        Queue::fake();
        $root = $this->scannerRootWith(['finance.dashcore.com', 'demo-sales.demo.test']);
        $this->artisan('tests:demo')->assertSuccessful();
        FleetApp::query()->where('key', 'demo-sales')->update(['local_path' => $root.'/demo-sales.demo.test']);
        FleetApp::factory()->create(['key' => 'finance', 'host' => 'finance.dashcore.com', 'local_path' => $root.'/finance.dashcore.com']);

        $this->artisan('tests:scan')->assertSuccessful();

        Queue::assertPushed(RunScan::class, 1);
        Queue::assertPushed(RunScan::class, fn (RunScan $job) => $job->scan->fleetApp->key === 'finance');
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        return collect(['fleet_apps', 'scans', 'surfaces', 'scan_surfaces', 'scan_tests', 'builds', 'library_files', 'library_file_revisions', 'library_tests', 'simulation_runs', 'simulation_steps', 'simulation_findings', 'launches', 'workflows', 'workflow_runs', 'workflow_run_steps', 'workflow_events'])
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])
            ->all();
    }
}
