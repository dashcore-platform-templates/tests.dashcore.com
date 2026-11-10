<?php

namespace Tests\Feature\Scanning;

use App\Models\FleetApp;
use App\Models\Scan;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Why the 7 and 8 Nov 2026 nightlies queued nothing (REQ-683): the home
 * install's .env had said SCANNER_NIGHTLY=false since 31 Oct, while the
 * launchd plists Console writes for its workers said true. schedule:run
 * reads only the .env, so tests:scan was never due, nothing recorded a
 * request, and tests:scans-stale — on only with the nightly — stayed quiet.
 * A switched-off nightly on an install with apps to scan is now a finding.
 */
class NightlyOffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        config(['scanner.stale_after_hours' => 26]);
    }

    private function nightlyScan(): Event
    {
        return collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_ends_with((string) $event->command, "'artisan' tests:scan"));
    }

    /** @return array<string, array<string, mixed>> */
    private function findings(): array
    {
        Artisan::call('console:report', ['--json' => true]);

        return collect(json_decode(Artisan::output(), true)['findings'])->keyBy('fingerprint')->all();
    }

    public function test_a_nightly_switched_off_in_the_env_is_never_due_and_is_reported(): void
    {
        $app = FleetApp::factory()->create(['key' => 'sales', 'is_enabled' => true, 'created_at' => now()->subDays(10)]);
        Scan::factory()->for($app)->completed()->create(['finished_at' => now()->subDays(2)]);

        // What the workers' launchd environment says does not reach the schedule.
        putenv('SCANNER_NIGHTLY=true');
        config(['scanner.nightly' => false]);

        try {
            $this->assertFalse($this->nightlyScan()->filtersPass($this->app), 'the schedule reads config, never the worker plist');
        } finally {
            putenv('SCANNER_NIGHTLY');
        }

        $findings = $this->findings();
        $this->assertArrayHasKey('tests:nightly-off', $findings);
        $this->assertSame('high', $findings['tests:nightly-off']['severity']);
        $this->assertStringContainsString('SCANNER_NIGHTLY is false', $findings['tests:nightly-off']['detail']);
        $this->assertStringContainsString('The last main scan finished', $findings['tests:nightly-off']['detail']);
        $this->assertArrayNotHasKey('tests:scans-stale', $findings, 'one finding for one problem');

        // Switched back on: the nightly is due and the finding goes; a stale fleet is then its own finding.
        config(['scanner.nightly' => true]);
        $this->assertTrue($this->nightlyScan()->filtersPass($this->app));

        $findings = $this->findings();
        $this->assertArrayNotHasKey('tests:nightly-off', $findings);
        $this->assertArrayHasKey('tests:scans-stale', $findings);
    }

    public function test_an_install_with_nothing_to_scan_may_keep_its_nightly_off(): void
    {
        config(['scanner.nightly' => false]);
        FleetApp::factory()->create(['key' => 'sales', 'is_enabled' => false]);

        $this->assertArrayNotHasKey('tests:nightly-off', $this->findings());
    }
}
