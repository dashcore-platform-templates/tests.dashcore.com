<?php

namespace Tests\Feature\Scanning;

use App\Enums\ScanStatus;
use App\Jobs\RunScan;
use App\Models\FleetApp;
use App\Models\Scan;
use App\Scanning\PullRequestStatus;
use App\Scanning\Scanner;
use App\Scanning\ScanRequester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\Concerns\UsesFleetFixtures;
use Tests\TestCase;

class ScanRequestTest extends TestCase
{
    use RefreshDatabase, UsesFleetFixtures;

    private function scannableApp(string $key = 'finance'): FleetApp
    {
        $root = $this->scannerRootWith([$key.'.dashcore.com']);

        return FleetApp::factory()->create(['key' => $key, 'host' => $key.'.dashcore.com', 'local_path' => $root.'/'.$key.'.dashcore.com']);
    }

    public function test_a_scan_is_queued_on_the_long_running_connection(): void
    {
        Queue::fake();
        $app = $this->scannableApp();

        $scan = app(ScanRequester::class)->request($app);

        $this->assertSame(ScanStatus::Queued, $scan->status);
        Queue::assertPushedOn('scans', RunScan::class, fn (RunScan $job) => $job->scan->is($scan) && $job->connection === 'scans');
        $this->assertGreaterThan(config('scanner.timeout'), (new RunScan($scan))->timeout);
        $this->assertGreaterThan((new RunScan($scan))->timeout, config('queue.connections.scans.retry_after'), 'a running scan must never be handed to a second worker');
    }

    public function test_an_app_already_scanning_is_not_scanned_twice(): void
    {
        Queue::fake();
        $app = $this->scannableApp();

        foreach ([ScanStatus::Queued, ScanStatus::Preparing, ScanStatus::Running, ScanStatus::Analysing] as $active) {
            $app->scans()->delete();
            $app->scans()->create(['status' => $active]);

            $this->assertNull(app(ScanRequester::class)->request($app), $active->value);
        }

        Queue::assertNothingPushed();

        $app->scans()->update(['status' => ScanStatus::Completed]);
        $this->assertNotNull(app(ScanRequester::class)->request($app));
    }

    public function test_an_app_with_no_checkout_or_switched_off_is_not_scanned(): void
    {
        Queue::fake();

        $this->assertNull(app(ScanRequester::class)->request(FleetApp::factory()->create()));

        $off = $this->scannableApp('hr');
        $off->update(['is_enabled' => false]);
        $this->assertNull(app(ScanRequester::class)->request($off));

        Queue::assertNothingPushed();
    }

    public function test_the_job_runs_a_queued_scan_and_ignores_one_that_already_ran(): void
    {
        $scan = Scan::factory()->create();
        $scanner = Mockery::mock(Scanner::class);
        $scanner->shouldReceive('run')->once()->with(Mockery::on(fn ($s) => $s->is($scan)))->andReturn($scan);

        (new RunScan($scan))->handle($scanner, app(PullRequestStatus::class));

        $done = Scan::factory()->completed()->create();
        $idle = Mockery::mock(Scanner::class);
        $idle->shouldNotReceive('run');
        (new RunScan($done))->handle($idle, app(PullRequestStatus::class));
    }

    public function test_a_worker_that_dies_marks_the_scan_failed_but_leaves_a_finished_one_alone(): void
    {
        $running = Scan::factory()->create(['status' => ScanStatus::Running]);
        (new RunScan($running))->failed(new RuntimeException('Killed'));

        $this->assertSame(ScanStatus::Failed, $running->fresh()->status);
        $this->assertStringContainsString('Killed', $running->fresh()->error);

        $done = Scan::factory()->completed()->create();
        (new RunScan($done))->failed(new RuntimeException('late'));
        $this->assertSame(ScanStatus::Completed, $done->fresh()->status);
    }

    public function test_the_scan_command_queues_scannable_apps_and_explains_the_rest(): void
    {
        Queue::fake();
        $this->scannableApp('finance');
        FleetApp::factory()->create(['key' => 'hr']);

        $this->artisan('tests:scan')
            ->expectsOutputToContain('finance')
            ->expectsOutputToContain('no local checkout')
            ->assertSuccessful();

        Queue::assertPushed(RunScan::class, 1);
    }

    public function test_the_scan_command_refuses_apps_not_in_the_fleet(): void
    {
        $this->artisan('tests:scan', ['app' => ['nope']])
            ->expectsOutputToContain('Not in the fleet: nope')
            ->assertFailed();
    }

    public function test_scanning_now_runs_in_process_without_queueing(): void
    {
        Queue::fake();
        $app = $this->scannableApp();
        $scanner = Mockery::mock(Scanner::class);
        $scanner->shouldReceive('run')->once()->andReturnUsing(fn (Scan $scan) => tap($scan)->update(['status' => ScanStatus::Completed, 'score' => 75, 'surfaces_tested' => 3, 'surfaces_total' => 4]));
        $this->app->instance(Scanner::class, $scanner);

        $this->artisan('tests:scan', ['app' => ['finance'], '--now' => true])
            ->expectsOutputToContain('75.00%')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }
}
