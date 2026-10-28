<?php

namespace Tests\Feature;

use App\Enums\ScanStatus;
use App\Models\FleetApp;
use App\Models\JourneyRun;
use App\Models\Scan;
use App\Workflows\Heartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Console reads every fleet's tests through `console:report --json`, its one
 * contract for every source: the fleet's failed scans, coverage regressions,
 * failing or drifted journeys and a silent heartbeat — and nothing when all
 * is well.
 */
class ConsoleReportTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function report(): array
    {
        Artisan::call('console:report', ['--json' => true]);

        return json_decode(Artisan::output(), true);
    }

    /** @return array<string, array<string, mixed>> */
    private function findings(): array
    {
        return collect($this->report()['findings'])->keyBy('fingerprint')->all();
    }

    private function aliveAndWell(): void
    {
        app(Heartbeat::class)->beat(true, 'Scans: 0 queued, 0 running.');
    }

    public function test_all_is_well_reports_no_findings_in_the_contracts_shape(): void
    {
        $this->aliveAndWell();
        $app = FleetApp::factory()->create(['key' => 'sales', 'host' => 'sales.dashcore.com']);
        Scan::factory()->for($app)->completed()->create();
        JourneyRun::create(['journey' => 'enquiry', 'fleet' => 'practice', 'outcome' => JourneyRun::PASSED, 'started_at' => now(), 'finished_at' => now()]);

        $report = $this->report();

        $this->assertSame('tests', $report['source']);
        $this->assertTrue($report['complete']);
        $this->assertSame([], $report['findings']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $report['generated_at']);
        $this->assertSame(['fleet' => 'dashcore', 'host' => 'tests.dashcore.com', 'home' => true], $report['facts']['install']);
    }

    public function test_a_failed_scan_and_lost_coverage_are_findings_on_their_app(): void
    {
        $this->aliveAndWell();
        $sales = FleetApp::factory()->create(['key' => 'sales', 'host' => 'sales.dashcore.com', 'name' => 'Sales']);
        Scan::factory()->for($sales)->completed()->create(['surfaces_lost' => 2, 'surfaces_new_untested' => 1]);
        $failed = Scan::factory()->for($sales)->create(['status' => ScanStatus::Failed, 'error' => 'composer install failed', 'finished_at' => now()]);

        $findings = $this->findings();

        $this->assertSame(['tests:regressions:sales', 'tests:scan-failed:sales'], collect($findings)->keys()->sort()->values()->all());
        $this->assertSame('sales.dashcore.com', $findings['tests:scan-failed:sales']['app']);
        $this->assertSame('high', $findings['tests:scan-failed:sales']['severity']);
        $this->assertStringContainsString('composer install failed', $findings['tests:scan-failed:sales']['detail']);
        $this->assertStringEndsWith('/scans/'.$failed->id, $findings['tests:scan-failed:sales']['url']);
        $this->assertSame('medium', $findings['tests:regressions:sales']['severity']);
        $this->assertStringContainsString('2 surface(s) lost coverage, 1 new surface(s) untested', $findings['tests:regressions:sales']['detail']);

        // The next scan passes and keeps its coverage: both are gone.
        Scan::factory()->for($sales)->completed()->create();
        $this->assertSame([], $this->findings());
    }

    public function test_failing_refused_and_drifted_journeys_are_findings_by_their_latest_walk(): void
    {
        $this->aliveAndWell();
        $walk = fn (string $journey, string $outcome, ?array $drift = null) => JourneyRun::create(['journey' => $journey, 'fleet' => 'practice', 'outcome' => $outcome, 'drift' => $drift, 'error' => $outcome === JourneyRun::PASSED ? null : 'Timeout 15000ms exceeded.', 'started_at' => now(), 'finished_at' => now()]);

        $walk('payment', JourneyRun::PASSED);
        $walk('payment', JourneyRun::FAILED);
        $walk('enquiry', JourneyRun::FAILED);
        $walk('enquiry', JourneyRun::PASSED, [['app' => 'sales', 'checkout' => '/x/sales.acme.practice', 'live' => 'v1.4.0', 'live_sha' => null, 'actual_sha' => null]]);
        $walk('subscription', JourneyRun::REFUSED);

        $findings = $this->findings();

        $this->assertSame([
            'tests:journey-drifted:practice:enquiry',
            'tests:journey-failed:practice:payment',
            'tests:journey-refused:practice:subscription',
        ], collect($findings)->keys()->sort()->values()->all());
        $this->assertSame('high', $findings['tests:journey-failed:practice:payment']['severity']);
        $this->assertSame('Timeout 15000ms exceeded.', $findings['tests:journey-failed:practice:payment']['detail']);
        $this->assertStringContainsString('sales (live v1.4.0)', $findings['tests:journey-drifted:practice:enquiry']['detail']);
        $this->assertNull($findings['tests:journey-drifted:practice:enquiry']['app'], 'about the install itself; Console files it under the site');
    }

    public function test_a_silent_or_failing_heartbeat_is_a_finding(): void
    {
        $this->assertArrayHasKey('tests:heartbeat-silent', $this->findings(), 'never ticked');

        app(Heartbeat::class)->beat(false, '3 scan(s) queued and nothing has taken one for 45 minutes');
        $findings = $this->findings();
        $this->assertSame(['tests:heartbeat-failing'], array_keys($findings));
        $this->assertStringContainsString('nothing has taken one', $findings['tests:heartbeat-failing']['detail']);

        $this->travel(11)->minutes();
        $this->assertSame(['tests:heartbeat-silent'], array_keys($this->findings()));
    }
}
