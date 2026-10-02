<?php

namespace Tests\Feature\Scanning;

use App\Models\FleetApp;
use App\Scanning\FleetSync;
use Dashcore\Bridge\Keys\ConfigKeysetResolver;
use Dashcore\Bridge\Keys\KeysetResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\UsesFleetFixtures;
use Tests\TestCase;

class FleetSyncTest extends TestCase
{
    use RefreshDatabase, UsesFleetFixtures;

    public function test_the_manifest_install_block_becomes_the_fleet(): void
    {
        $root = $this->scannerRootWith(['finance.dashcore.com']);
        $this->fakeInstall([
            'api' => 'api',
            'finance' => 'finance.dashcore.com.test',
            'delivery' => 'dcos.dashcore.com.test',
        ]);

        $result = app(FleetSync::class)->sync();

        $this->assertNull($result['error']);
        $this->assertSame(['api', 'finance', 'delivery'], $result['added']);

        $finance = FleetApp::where('key', 'finance')->sole();
        $this->assertSame('finance.dashcore.com', $finance->host);
        $this->assertSame('Finance', $finance->name);
        $this->assertSame('Domain service', $finance->group);
        $this->assertSame('dashcoretech/finance.dashcore.com', $finance->repo);
        $this->assertSame($root.'/finance.dashcore.com', $finance->local_path);

        $this->assertSame('dcos.dashcore.com', FleetApp::where('key', 'delivery')->sole()->host, 'hosts come from the catalogue, not the enrolled name');
        $this->assertSame('DCOS (Delivery)', FleetApp::where('key', 'delivery')->sole()->name);
        $this->assertSame('API', FleetApp::where('key', 'api')->sole()->name);
        $this->assertNull(FleetApp::where('key', 'api')->sole()->local_path, 'no checkout of api in this root');
    }

    public function test_a_client_install_uses_its_own_domain(): void
    {
        $this->fakeInstall(['finance' => 'finance.elitemcare.com.test'], 'elitemcare.com');

        app(FleetSync::class)->sync();

        $this->assertSame('finance.elitemcare.com', FleetApp::sole()->host);
    }

    public function test_apps_leaving_the_fleet_keep_their_history_and_come_back_with_it(): void
    {
        $this->fakeInstall(['finance' => 'finance.dashcore.com.test', 'hr' => 'hr.dashcore.com.test']);
        app(FleetSync::class)->sync();
        $hr = FleetApp::where('key', 'hr')->sole();
        $hr->scans()->create(['status' => 'completed']);

        $this->fakeInstall(['finance' => 'finance.dashcore.com.test']);
        $result = app(FleetSync::class)->sync();

        $this->assertSame(['hr'], $result['removed']);
        $this->assertSoftDeleted($hr);
        $this->assertSame(['finance'], FleetApp::pluck('key')->all());

        $this->fakeInstall(['finance' => 'finance.dashcore.com.test', 'hr' => 'hr.dashcore.com.test']);
        $result = app(FleetSync::class)->sync();

        $this->assertSame(['hr'], $result['added']);
        $this->assertSame(1, FleetApp::where('key', 'hr')->sole()->scans()->count());
    }

    public function test_a_resync_reports_only_what_changed(): void
    {
        $this->fakeInstall(['finance' => 'finance.dashcore.com.test']);
        app(FleetSync::class)->sync();

        $unchanged = app(FleetSync::class)->sync();
        $this->assertSame([], $unchanged['updated']);

        $this->scannerRootWith(['finance.dashcore.com']);
        $changed = app(FleetSync::class)->sync();
        $this->assertSame(['finance'], $changed['updated'], 'a checkout appearing is a change');
    }

    public function test_an_app_not_connected_to_the_fleet_reads_no_roster_and_changes_nothing(): void
    {
        FleetApp::factory()->create(['key' => 'finance']);
        $this->app->instance(KeysetResolver::class, new ConfigKeysetResolver);

        $result = app(FleetSync::class)->sync();

        $this->assertStringContainsString('not connected to the fleet', $result['error']);
        $this->assertSame(1, FleetApp::count());
        $this->assertSame($result['error'], FleetSync::lastResult()['error']);
    }

    public function test_an_unreachable_control_plane_leaves_the_last_known_fleet_in_place(): void
    {
        FleetApp::factory()->create(['key' => 'finance']);
        $this->fakeManifest(new RuntimeException('connection refused'));

        $result = app(FleetSync::class)->sync();

        $this->assertStringContainsString('connection refused', $result['error']);
        $this->assertSame(1, FleetApp::count());
    }

    public function test_a_manifest_without_an_install_block_changes_nothing(): void
    {
        FleetApp::factory()->create(['key' => 'finance']);
        $this->fakeManifest(['apps' => [], 'grants' => []]);

        $result = app(FleetSync::class)->sync();

        $this->assertStringContainsString('no install block', $result['error']);
        $this->assertSame(1, FleetApp::count());
    }

    public function test_the_command_reports_changes_and_fails_on_error(): void
    {
        $this->fakeInstall(['finance' => 'finance.dashcore.com.test']);
        $this->artisan('tests:sync-fleet')->expectsOutputToContain('finance')->assertSuccessful();

        $this->fakeManifest(new RuntimeException('hub down'));
        $this->artisan('tests:sync-fleet')->expectsOutputToContain('hub down')->assertFailed();
    }
}
