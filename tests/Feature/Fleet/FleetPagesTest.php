<?php

namespace Tests\Feature\Fleet;

use App\Enums\ScanStatus;
use App\Enums\SurfaceKind;
use App\Enums\SurfaceStatus;
use App\Enums\TestOutcome;
use App\Jobs\RunScan;
use App\Livewire\Fleet\Index;
use App\Livewire\Fleet\Show;
use App\Livewire\Scans\Show as ScanShow;
use App\Models\FleetApp;
use App\Models\Scan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\UsesFleetFixtures;
use Tests\TestCase;

class FleetPagesTest extends TestCase
{
    use RefreshDatabase, UsesFleetFixtures;

    private function scannedApp(): FleetApp
    {
        $root = $this->scannerRootWith(['finance.dashcore.com']);
        $app = FleetApp::factory()->create(['key' => 'finance', 'name' => 'Finance', 'host' => 'finance.dashcore.com', 'local_path' => $root.'/finance.dashcore.com']);

        $scan = Scan::factory()->completed(50)->for($app)->create(['surfaces_total' => 4, 'surfaces_tested' => 2, 'surfaces_weak' => 1, 'surfaces_missing' => 1, 'tests_failed' => 1]);

        foreach ([
            ['GET reports', SurfaceKind::Route, SurfaceStatus::Tested],
            ['POST reports', SurfaceKind::Route, SurfaceStatus::Missing],
            ['App\Jobs\SendReport::handle', SurfaceKind::Job, SurfaceStatus::Weak],
            ['App\Jobs\SendReport::failed', SurfaceKind::Job, SurfaceStatus::Tested],
        ] as [$identifier, $kind, $status]) {
            $surface = $app->surfaces()->create(['kind' => $kind, 'identifier' => $identifier, 'label' => $identifier, 'status' => $status, 'last_scan_id' => $scan->id]);
            $scan->surfaceResults()->create(['surface_id' => $surface->id, 'status' => $status, 'tests_count' => 1, 'evidence' => [['id' => 'T::a', 'name' => 'lists reports', 'file' => 'tests/T.php', 'outcome' => 'passed']]]);
        }

        $scan->tests()->create(['test_id' => 'T::broken', 'name' => 'breaks on empty data', 'file' => 'tests/T.php', 'outcome' => TestOutcome::Failed, 'message' => 'Undefined index']);
        $scan->tests()->create(['test_id' => 'T::hollow', 'name' => 'renders', 'file' => 'tests/T.php', 'outcome' => TestOutcome::Passed, 'assertions' => 0]);

        return $app;
    }

    public function test_guests_are_sent_to_log_in(): void
    {
        $this->get(route('fleet.index'))->assertRedirect(route('login'));
    }

    public function test_every_role_can_read_the_fleet(): void
    {
        $app = $this->scannedApp();
        $scan = $app->scans()->sole();

        foreach (['owner', 'admin', 'finance', 'member'] as $role) {
            $user = User::factory()->{$role === 'member' ? 'state' : $role}([])->create();

            $this->actingAs($user)->get(route('fleet.index'))->assertOk()->assertSee('Finance')->assertSee('50.0%');
            $this->actingAs($user)->get(route('fleet.show', 'finance'))->assertOk()->assertSee('POST reports');
            $this->actingAs($user)->get(route('scans.show', $scan))->assertOk()->assertSee('breaks on empty data');
        }
    }

    public function test_an_app_or_scan_that_does_not_exist_is_not_found(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $this->get(route('fleet.show', 'nope'))->assertNotFound();
        $this->get('/scans/999')->assertNotFound();
    }

    public function test_the_matrix_totals_are_weighted_by_surface(): void
    {
        $this->scannedApp();
        Scan::factory()->completed(100)->for(FleetApp::factory()->create(['name' => 'Tiny']))->create(['surfaces_total' => 1, 'surfaces_tested' => 1, 'surfaces_missing' => 0]);

        Livewire::actingAs(User::factory()->create())
            ->test(Index::class)
            ->assertSet('totals.score', 60.0)
            ->assertSee('3 of 5 surfaces tested')
            ->assertSee('2 of 2 apps scanned');
    }

    public function test_owners_and_admins_can_scan_and_members_cannot(): void
    {
        Queue::fake();
        $app = $this->scannedApp();

        Livewire::actingAs(User::factory()->create())
            ->test(Index::class)
            ->assertDontSee('Scan all')
            ->call('scan', $app->id)
            ->assertForbidden();

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(Index::class)
            ->call('scan', $app->id)
            ->assertDispatched('fleet-notice', message: 'Scan of Finance queued.');

        Queue::assertPushed(RunScan::class, 1);

        Livewire::actingAs(User::factory()->owner()->create())
            ->test(Index::class)
            ->call('scan', $app->id)
            ->assertDispatched('fleet-notice', message: 'Finance is already being scanned.');

        Queue::assertPushed(RunScan::class, 1);
        $this->assertSame(ScanStatus::Queued, $app->latestScan()->first()->status);
    }

    public function test_scan_all_and_sync_are_owner_and_admin_only(): void
    {
        Queue::fake();
        $this->scannedApp();
        $this->fakeInstall(['finance' => 'finance.dashcore.com.test']);

        Livewire::actingAs(User::factory()->create())->test(Index::class)->call('scanAll')->assertForbidden();
        Livewire::actingAs(User::factory()->create())->test(Index::class)->call('syncFleet')->assertForbidden();

        Livewire::actingAs(User::factory()->owner()->create())
            ->test(Index::class)
            ->call('scanAll')
            ->assertDispatched('fleet-notice', message: '1 scan(s) queued.')
            ->call('syncFleet')
            ->assertDispatched('fleet-notice', message: 'Fleet synced: 0 added, 1 updated, 0 removed.');
    }

    public function test_a_failed_roster_read_is_explained_on_the_matrix(): void
    {
        $this->fakeManifest(new \RuntimeException('hub down'));

        Livewire::actingAs(User::factory()->owner()->create())
            ->test(Index::class)
            ->call('syncFleet')
            ->assertSee('The fleet roster could not be read')
            ->assertSee('hub down');
    }

    public function test_an_empty_fleet_says_where_the_roster_comes_from(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(Index::class)
            ->assertSee('No apps in the fleet yet.');
    }

    public function test_the_app_page_shows_open_work_first_and_filters_by_status_kind_and_search(): void
    {
        $this->scannedApp();
        $component = Livewire::actingAs(User::factory()->create())->test(Show::class, ['appKey' => 'finance']);

        $component->assertSee('POST reports')->assertSee('App\Jobs\SendReport::handle')->assertDontSee('App\Jobs\SendReport::failed');

        $component->set('status', 'tested')->assertSee('App\Jobs\SendReport::failed')->assertDontSee('POST reports');
        $component->set('status', 'all')->set('kind', 'route')->assertSee('POST reports')->assertDontSee('SendReport');
        $component->set('kind', '')->set('search', 'sendreport')->assertSee('App\Jobs\SendReport::handle')->assertDontSee('POST reports');
        $component->assertSee('breaks on empty data')->assertSee('Undefined index');
    }

    public function test_the_app_page_scan_button_respects_permissions(): void
    {
        Queue::fake();
        $this->scannedApp();

        Livewire::actingAs(User::factory()->create())->test(Show::class, ['appKey' => 'finance'])->assertDontSee('Scan now')->call('scan')->assertForbidden();
        Livewire::actingAs(User::factory()->owner()->create())->test(Show::class, ['appKey' => 'finance'])->call('scan')->assertDispatched('fleet-notice', message: 'Scan queued.');

        Queue::assertPushed(RunScan::class, 1);
    }

    public function test_an_app_never_scanned_says_so(): void
    {
        FleetApp::factory()->create(['key' => 'hr', 'name' => 'HR']);

        Livewire::actingAs(User::factory()->create())
            ->test(Show::class, ['appKey' => 'hr'])
            ->assertSee('This app has not been scanned yet.')
            ->assertSee('There is no checkout of this app');
    }

    public function test_the_scan_page_lists_tests_that_fail_or_prove_nothing(): void
    {
        $scan = $this->scannedApp()->scans()->sole();

        Livewire::actingAs(User::factory()->create())
            ->test(ScanShow::class, ['scanId' => $scan->id])
            ->assertSee('Failing tests (1)')
            ->assertSee('Green but proving nothing (1)')
            ->assertSee('renders');
    }

    public function test_a_failed_scan_shows_its_error(): void
    {
        $scan = Scan::factory()->create(['status' => ScanStatus::Failed, 'error' => 'composer install failed: boom']);

        Livewire::actingAs(User::factory()->create())
            ->test(ScanShow::class, ['scanId' => $scan->id])
            ->assertSee('The scan failed')
            ->assertSee('composer install failed: boom');
    }
}
