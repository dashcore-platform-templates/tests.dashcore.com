<?php

namespace Tests\Feature\Scanning;

use App\Enums\ScanKind;
use App\Enums\ScanStatus;
use App\Jobs\RunScan;
use App\Models\FleetApp;
use App\Models\Scan;
use App\Scanning\PullRequestStatus;
use App\Scanning\ScanHeartbeat;
use App\Scanning\ScanReaper;
use App\Scanning\WorkerProcess;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Concerns\UsesFleetFixtures;
use Tests\TestCase;

/**
 * A worker launchd killed mid-scan once left ten scans "running" for the
 * whole retry_after (14 hours); then they failed "attempted too many times"
 * and every app read "already scanning" in between (REQ-683).
 */
class ReapScansTest extends TestCase
{
    use RefreshDatabase, UsesFleetFixtures;

    private bool $workerAlive = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(WorkerProcess::class, new class($this) extends WorkerProcess
        {
            public function __construct(private ReapScansTest $test) {}

            public function alive(?string $host, ?int $pid): bool
            {
                return $this->test->workerIsAlive();
            }
        });
    }

    public function workerIsAlive(): bool
    {
        return $this->workerAlive;
    }

    private function scannableApp(string $key = 'finance'): FleetApp
    {
        $root = $this->scannerRootWith([$key.'.dashcore.com']);

        return FleetApp::factory()->create(['key' => $key, 'host' => $key.'.dashcore.com', 'local_path' => $root.'/'.$key.'.dashcore.com']);
    }

    /** A real RunScan job row on the scans connection, as a worker left it reserved. */
    private function reservedJobFor(Scan $scan): int
    {
        RunScan::dispatch($scan);
        $id = (int) DB::table('jobs')->max('id');
        DB::table('jobs')->where('id', $id)->update(['reserved_at' => now()->subHours(3)->getTimestamp(), 'attempts' => 1]);

        return $id;
    }

    public function test_a_scan_whose_heartbeat_stopped_and_whose_worker_is_gone_is_failed_and_queued_again(): void
    {
        $app = $this->scannableApp();
        $scan = $app->scans()->create(['status' => ScanStatus::Running, 'started_at' => now()->subHours(3), 'heartbeat_at' => now()->subMinutes(20), 'worker_pid' => 4242, 'worker_host' => gethostname()]);
        $job = $this->reservedJobFor($scan);
        $other = Scan::factory()->create(['status' => ScanStatus::Queued]);
        $otherJob = (function () use ($other) {
            RunScan::dispatch($other);

            return (int) DB::table('jobs')->max('id');
        })();

        $this->artisan('tests:reap-scans')->assertSuccessful();

        $scan->refresh();
        $this->assertSame(ScanStatus::Failed, $scan->status);
        $this->assertStringStartsWith(ScanReaper::ERROR_PREFIX, $scan->error);
        $this->assertStringContainsString('no heartbeat since', $scan->error);
        $this->assertStringContainsString('pid 4242', $scan->error);
        $this->assertNotNull($scan->finished_at);

        $this->assertFalse(DB::table('jobs')->where('id', $job)->exists(), 'its reserved job is deleted');
        $this->assertTrue(DB::table('jobs')->where('id', $otherJob)->exists(), 'another scan\'s job is left alone');

        $fresh = $app->scans()->where('status', ScanStatus::Queued)->sole();
        $this->assertNotSame($scan->id, $fresh->id);
        $this->assertTrue(DB::table('jobs')->get()->contains(fn ($row) => str_contains((string) json_decode($row->payload, true)['data']['command'], 's:2:"id";i:'.$fresh->id.';')), 'the fresh scan is on the queue');
    }

    public function test_a_slow_scan_whose_worker_is_still_alive_is_left_running(): void
    {
        Queue::fake();
        $this->workerAlive = true;
        $scan = $this->scannableApp()->scans()->create(['status' => ScanStatus::Mutating, 'started_at' => now()->subHours(3), 'heartbeat_at' => now()->subMinutes(30), 'worker_pid' => 4242]);

        $this->assertSame([], app(ScanReaper::class)->reap());
        $this->assertSame(ScanStatus::Mutating, $scan->refresh()->status);
    }

    public function test_a_scan_with_a_recent_heartbeat_is_never_asked_about(): void
    {
        Queue::fake();
        $scan = $this->scannableApp()->scans()->create(['status' => ScanStatus::Running, 'started_at' => now()->subHours(3), 'heartbeat_at' => now()->subMinutes(2)]);

        $this->assertSame([], app(ScanReaper::class)->reap());
        $this->assertSame(ScanStatus::Running, $scan->refresh()->status);
    }

    public function test_an_app_is_queued_again_only_once_a_day(): void
    {
        Queue::fake();
        $app = $this->scannableApp();

        $app->scans()->create(['status' => ScanStatus::Running, 'started_at' => now()->subHours(3)]);
        $first = app(ScanReaper::class)->reap();
        $this->assertNotNull($first[0]['requeued']);

        $first[0]['requeued']->update(['status' => ScanStatus::Running, 'started_at' => now()->subHours(1)]);
        Scan::query()->whereKey($first[0]['requeued']->id)->update(['updated_at' => now()->subHour()]);
        $second = app(ScanReaper::class)->reap();

        $this->assertSame(ScanStatus::Failed, $second[0]['scan']->refresh()->status);
        $this->assertNull($second[0]['requeued'], 'a scan that kills its worker every time does not loop');
        Queue::assertPushed(RunScan::class, 1);
    }

    public function test_a_queued_scan_whose_job_is_gone_is_failed_and_queued_again(): void
    {
        $app = $this->scannableApp();
        $lost = $app->scans()->create(['status' => ScanStatus::Queued]);
        Scan::query()->whereKey($lost->id)->update(['updated_at' => now()->subHour()]);
        $waiting = Scan::factory()->create(['status' => ScanStatus::Queued]);
        Scan::query()->whereKey($waiting->id)->update(['updated_at' => now()->subHour()]);
        RunScan::dispatch($waiting);

        app(ScanReaper::class)->reap();

        $this->assertSame(ScanStatus::Failed, $lost->refresh()->status);
        $this->assertStringContainsString('queued job is gone', $lost->error);
        $this->assertSame(ScanStatus::Queued, $waiting->refresh()->status, 'a scan still on the queue keeps waiting');
        $this->assertSame(1, $app->scans()->where('status', ScanStatus::Queued)->count());
    }

    public function test_a_dead_pull_request_scan_is_failed_and_reported_but_not_queued_again(): void
    {
        Queue::fake();
        $status = Mockery::mock(PullRequestStatus::class);
        $status->shouldReceive('publish')->once()->andReturn(true);
        $this->app->instance(PullRequestStatus::class, $status);
        $app = $this->scannableApp();
        $scan = $app->scans()->create(['kind' => ScanKind::PullRequest, 'pr_number' => 7, 'status' => ScanStatus::Running, 'started_at' => now()->subHours(3)]);

        $reaped = app(ScanReaper::class)->reap();

        $this->assertSame(ScanStatus::Failed, $scan->refresh()->status);
        $this->assertNull($reaped[0]['requeued']);
        Queue::assertNothingPushed();
    }

    public function test_the_scanner_stamps_its_heartbeat_and_worker(): void
    {
        $scan = Scan::factory()->create(['status' => ScanStatus::Running]);
        $heartbeat = app(ScanHeartbeat::class);

        $heartbeat->start($scan);

        $scan->refresh();
        $this->assertNotNull($scan->heartbeat_at);
        $this->assertSame(getmypid(), $scan->worker_pid);
        $this->assertSame(gethostname(), $scan->worker_host);

        Scan::query()->whereKey($scan->id)->update(['heartbeat_at' => now()->subHour()]);
        $heartbeat->beat();
        $this->assertTrue($scan->refresh()->heartbeat_at->lt(now()->subMinutes(30)), 'beats are at most once a minute');

        $heartbeat->stop();
    }

    public function test_the_worker_check_asks_the_process_table(): void
    {
        Process::fake(['*' => Process::result('/usr/bin/php artisan queue:work scans --queue=scans,builds')]);
        $this->assertTrue((new WorkerProcess)->alive(gethostname(), 123));

        Process::fake(['*' => Process::result('', '', 1)]);
        $this->assertFalse((new WorkerProcess)->alive(gethostname(), 123));
        $this->assertFalse((new WorkerProcess)->alive(gethostname(), null));
        $this->assertFalse((new WorkerProcess)->alive('another-mac', 123));
    }

    public function test_the_reaper_is_scheduled(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'tests:reap-scans'));

        $this->assertCount(1, $events);
        $this->assertSame('*/5 * * * *', $events->first()->expression);
    }
}
