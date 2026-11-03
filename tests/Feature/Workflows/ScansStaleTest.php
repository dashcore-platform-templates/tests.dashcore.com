<?php

namespace Tests\Feature\Workflows;

use App\Enums\ScanKind;
use App\Models\FleetApp;
use App\Models\Scan;
use App\Models\Setting;
use App\Workflows\Heartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The 1 and 2 Nov 2026 nightlies queued nothing and the heartbeat still said
 * "Scans: 0 queued, 0 running" (REQ-476). After 26 hours with no completed
 * main scan, the heartbeat beats /fail and Console's report files
 * tests:scans-stale — and both are healthy again once a scan completes.
 */
class ScansStaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['workflows.heartbeat_url' => 'https://hc.example.test/ping/abc', 'scanner.nightly' => true, 'scanner.stale_after_hours' => 26]);
        Http::fake();
    }

    /** @return array<string, array<string, mixed>> */
    private function findings(): array
    {
        Artisan::call('console:report', ['--json' => true]);

        return collect(json_decode(Artisan::output(), true)['findings'])->keyBy('fingerprint')->all();
    }

    public function test_no_completed_main_scan_in_26_hours_is_unhealthy_until_one_completes(): void
    {
        $app = FleetApp::factory()->create(['key' => 'sales', 'created_at' => now()->subDays(10)]);
        Scan::factory()->for($app)->completed()->create(['finished_at' => now()->subHours(25)]);

        $this->artisan('tests:heartbeat')->assertSuccessful();
        Http::assertSent(fn (Request $request) => $request->url() === 'https://hc.example.test/ping/abc');
        $this->assertArrayNotHasKey('tests:scans-stale', $this->findings());

        // A night passes with nothing scanned.
        $this->travel(2)->hours();
        Http::fake();

        $this->artisan('tests:heartbeat')->assertSuccessful();
        Http::assertSent(fn (Request $request) => $request->url() === 'https://hc.example.test/ping/abc/fail'
            && str_contains($request->body(), 'No main scan has completed in 26 hours'));
        $this->assertFalse(Setting::get(Heartbeat::LAST_BEAT)['healthy']);

        $findings = $this->findings();
        $this->assertArrayHasKey('tests:scans-stale', $findings);
        $this->assertSame('high', $findings['tests:scans-stale']['severity']);
        $this->assertStringContainsString('No main scan has completed in 26 hours', $findings['tests:scans-stale']['detail']);
        $this->assertArrayNotHasKey('tests:heartbeat-failing', $findings, 'one finding for one problem');

        // A PR scan is no main scan.
        Scan::factory()->for($app)->completed()->create(['kind' => ScanKind::PullRequest, 'finished_at' => now()]);
        $this->assertArrayHasKey('tests:scans-stale', $this->findings());

        // The next main scan completes: healthy again.
        Scan::factory()->for($app)->completed()->create(['finished_at' => now()]);
        Http::fake();

        $this->artisan('tests:heartbeat')->assertSuccessful();
        Http::assertSent(fn (Request $request) => $request->url() === 'https://hc.example.test/ping/abc');
        $this->assertTrue(Setting::get(Heartbeat::LAST_BEAT)['healthy']);
        $this->assertSame([], $this->findings());
    }

    public function test_a_roster_that_never_had_a_completed_scan_goes_stale_26_hours_after_its_first_app(): void
    {
        FleetApp::factory()->create(['key' => 'sales', 'local_path' => null]);

        $this->assertArrayNotHasKey('tests:scans-stale', $this->findings());

        $this->travel(27)->hours();

        $this->assertStringContainsString('No main scan has ever completed here', $this->findings()['tests:scans-stale']['detail']);
    }

    public function test_nothing_is_stale_with_the_nightly_off_or_no_enabled_app(): void
    {
        $app = FleetApp::factory()->create(['key' => 'sales', 'created_at' => now()->subDays(10), 'is_enabled' => false]);

        $this->assertArrayNotHasKey('tests:scans-stale', $this->findings());

        $app->update(['is_enabled' => true]);
        config(['scanner.nightly' => false]);

        $this->assertArrayNotHasKey('tests:scans-stale', $this->findings());
    }

    public function test_stale_scans_beside_another_broken_part_still_report_the_heartbeat_failing(): void
    {
        FleetApp::factory()->create(['key' => 'sales', 'created_at' => now()->subDays(10)]);
        Scan::factory()->create(['created_at' => now()->subHour()]);
        config(['scanner.stalled_after_minutes' => 45]);

        $this->artisan('tests:heartbeat')->assertSuccessful();

        $findings = $this->findings();
        $this->assertArrayHasKey('tests:scans-stale', $findings);
        $this->assertArrayHasKey('tests:heartbeat-failing', $findings);
        $this->assertStringContainsString('nothing has taken one', $findings['tests:heartbeat-failing']['detail']);
    }
}
