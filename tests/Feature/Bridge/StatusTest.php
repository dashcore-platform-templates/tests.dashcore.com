<?php

namespace Tests\Feature\Bridge;

use App\Enums\ScanKind;
use App\Enums\ScanStatus;
use App\Models\FleetApp;
use App\Models\Scan;
use Dashcore\Bridge\Testing\InteractsWithBridge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StatusTest extends TestCase
{
    use InteractsWithBridge;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['bridge.driver' => 'config']);
    }

    public function test_fleets_reads_each_apps_latest_and_last_finished_scan(): void
    {
        $talent = FleetApp::factory()->create(['key' => 'talent', 'host' => 'talent.dashcore.com']);
        Scan::factory()->completed(72.5)->for($talent)->create(['kind' => ScanKind::Main, 'tests_failed' => 2, 'tests_passed' => 8]);
        // A scan still running is the latest, but the finished one keeps its counts.
        Scan::factory()->for($talent)->create(['kind' => ScanKind::Main, 'status' => ScanStatus::Running]);
        FleetApp::factory()->create(['key' => 'audit', 'host' => 'audit.dashcore.com']);

        $apps = collect($this->readStatus()->assertOk()->json('data.apps'))->keyBy('host');

        $this->assertSame(['audit.dashcore.com', 'talent.dashcore.com'], $apps->keys()->all());
        $this->assertSame('running', $apps['talent.dashcore.com']['latest']['status']);
        $this->assertSame(2, $apps['talent.dashcore.com']['completed']['tests']['failed']);
        $this->assertSame(72.5, $apps['talent.dashcore.com']['completed']['score']);
        $this->assertNull($apps['audit.dashcore.com']['latest']);
        $this->assertNull($apps['audit.dashcore.com']['completed']);
    }

    public function test_a_disabled_app_is_left_out(): void
    {
        FleetApp::factory()->create(['key' => 'old', 'host' => 'old.dashcore.com', 'is_enabled' => false]);

        $this->assertSame([], $this->readStatus()->assertOk()->json('data.apps'));
    }

    public function test_an_unsigned_request_cannot_read_the_status(): void
    {
        $this->getJson('/api/bridge/v1/status')->assertUnauthorized();
    }

    private function readStatus(): TestResponse
    {
        $this->registerBridgePeer('fleets.dashcore.com', ['tests.status.read']);

        // get(), not getJson(): getJson sends `[]` as the body, which the
        // signature (it hashes the body) would not match.
        return $this->withHeaders([...$this->bridgeHeaders('fleets.dashcore.com', 'GET', '/api/bridge/v1/status'), 'Accept' => 'application/json'])
            ->get('/api/bridge/v1/status');
    }
}
