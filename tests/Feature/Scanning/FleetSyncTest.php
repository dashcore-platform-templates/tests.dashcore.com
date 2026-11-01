<?php

namespace Tests\Feature\Scanning;

use App\Models\FleetApp;
use App\Scanning\FleetSync;
use App\Services\FleetExport;
use Dashcore\Bridge\Keys\ConfigKeysetResolver;
use Dashcore\Bridge\Keys\KeysetResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
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

    /** fleets' export for the practice fleet req145: each app's local folder. */
    private function practiceFleet(array $locals): void
    {
        config(['install.fleet' => 'practice-req145', 'install.home_fleet' => 'dashcore']);

        $export = Mockery::mock(FleetExport::class);
        $export->shouldReceive('live')->andReturnUsing(
            fn (string $slug, string $app) => $slug === 'practice-req145' && array_key_exists($app, $locals)
                ? ['release' => 'v1.0.0', 'commit' => null, 'local' => $locals[$app]]
                : null,
        );
        $this->app->instance(FleetExport::class, $export);
    }

    public function test_a_practice_fleet_finds_its_checkouts_under_the_fleets_local_folder(): void
    {
        $root = $this->scannerRootWith(['api.req145.practice', 'finance.req145.practice']);
        $this->practiceFleet(['api' => 'api.req145.practice', 'finance' => 'finance.req145.practice', 'hr' => null]);
        $this->fakeInstall([
            'api' => 'api.practice-req145.practice.dashcore.com',
            'finance' => 'finance.practice-req145.practice.dashcore.com',
            'hr' => 'hr.practice-req145.practice.dashcore.com',
        ], 'practice-req145.practice.dashcore.com');

        $this->assertNull(app(FleetSync::class)->sync()['error']);

        $api = FleetApp::where('key', 'api')->sole();
        $this->assertSame('api.practice-req145.practice.dashcore.com', $api->host, 'the roster host is kept');
        $this->assertSame($root.'/api.req145.practice', $api->local_path);
        $this->assertSame($root.'/finance.req145.practice', FleetApp::where('key', 'finance')->sole()->local_path);
        $this->assertNull(FleetApp::where('key', 'hr')->sole()->local_path, 'fleets names no folder for hr');
    }

    public function test_the_fleets_local_folder_gets_the_same_checks_as_a_host(): void
    {
        $root = $this->scannerRootWith(['api.req145.practice']);
        mkdir($root.'/notes.req145.practice');
        $outside = $this->scannerRoot.'-outside';
        mkdir($outside);
        file_put_contents($outside.'/artisan', '<?php');
        symlink($outside, $root.'/evil.req145.practice');

        try {
            $this->practiceFleet([
                'api' => $root.'/api.req145.practice',
                'finance' => '../'.basename($outside),
                'hr' => 'notes.req145.practice',
                'ceo' => 'evil.req145.practice',
            ]);
            $this->fakeInstall([
                'api' => 'api.practice-req145.practice.dashcore.com',
                'finance' => 'finance.practice-req145.practice.dashcore.com',
                'hr' => 'hr.practice-req145.practice.dashcore.com',
                'ceo' => 'ceo.practice-req145.practice.dashcore.com',
            ], 'practice-req145.practice.dashcore.com');

            app(FleetSync::class)->sync();

            $this->assertNull(FleetApp::where('key', 'api')->sole()->local_path, 'a path with / is refused');
            $this->assertNull(FleetApp::where('key', 'finance')->sole()->local_path, 'a path with .. is refused');
            $this->assertNull(FleetApp::where('key', 'hr')->sole()->local_path, 'a folder without artisan is refused');
            $this->assertNull(FleetApp::where('key', 'ceo')->sole()->local_path, 'a folder leading out of the root is refused');
        } finally {
            unlink($outside.'/artisan');
            rmdir($outside);
        }
    }

    public function test_the_home_install_finds_checkouts_by_host_alone(): void
    {
        $root = $this->scannerRootWith(['finance.dashcore.com', 'api.req145.practice']);
        $export = Mockery::mock(FleetExport::class);
        $export->shouldNotReceive('live');
        $this->app->instance(FleetExport::class, $export);
        $this->fakeInstall(['finance' => 'finance.dashcore.com.test', 'api' => 'api']);

        app(FleetSync::class)->sync();

        $this->assertSame($root.'/finance.dashcore.com', FleetApp::where('key', 'finance')->sole()->local_path);
        $this->assertNull(FleetApp::where('key', 'api')->sole()->local_path, 'fleets is never asked at home');
    }
}
