<?php

namespace Tests\Feature\Scanning;

use App\Enums\ScanKind;
use App\Enums\ScanStatus;
use App\Jobs\RunScan;
use App\Models\FleetApp;
use App\Models\Scan;
use App\Scanning\HostRuntime;
use App\Scanning\PullRequestStatus;
use App\Scanning\Scanner;
use App\Scanning\ScanSlots;
use App\Scanning\Workspace;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Every fleet's tests runs on the same Mac: two installs must never share a
 * scratch database, a worktree or a launchd worker, and together they must
 * never run more suites at once than the host's scan slots allow.
 */
class InstallsOnOneHostTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<ScanSlots> */
    private array $slots = [];

    protected function tearDown(): void
    {
        foreach ($this->slots as $slots) {
            $slots->release();
        }

        foreach (['slots_a', 'slots_b', 'slots_c'] as $name) {
            DB::purge($name);
        }

        parent::tearDown();
    }

    private function asInstall(string $fleet): void
    {
        config(['install.fleet' => $fleet, 'install.host' => $fleet === 'dashcore' ? 'tests.dashcore.com' : "tests.{$fleet}.practice.dashcore.com"]);
    }

    /** A slot pool as another worker process (another install) sees it: its own database session. */
    private function worker(string $name): ScanSlots
    {
        config(["database.connections.{$name}" => config('database.connections.'.config('scanner.slots.connection'))]);

        return $this->slots[] = new ScanSlots($name);
    }

    public function test_two_installs_get_different_scratch_databases_and_worktrees(): void
    {
        $app = FleetApp::factory()->create(['key' => 'sales']);
        $scan = Scan::factory()->for($app)->create();
        $pr = Scan::factory()->for($app)->create(['kind' => ScanKind::PullRequest, 'pr_number' => 12]);

        $this->asInstall('dashcore');
        $home = new Workspace($app, $scan);
        $homePr = new Workspace($app, $pr);

        $this->asInstall('acme');
        $acme = new Workspace($app, $scan);
        $acmePr = new Workspace($app, $pr);

        // The home install keeps the names it always had.
        $this->assertSame('tests_scan_sales', $home->database);
        $this->assertSame('tests_scan_sales_pr12', $homePr->database);
        $this->assertSame('tests_scan_acme__sales', $acme->database);
        $this->assertSame('tests_scan_acme__sales_pr12', $acmePr->database);
        $this->assertNotSame($home->path, $acme->path);
        $this->assertStringContainsString('/acme__sales/', $acme->path);
    }

    public function test_two_installs_get_different_worker_labels(): void
    {
        config(['scanner.pull_requests.enabled' => true]);

        $this->asInstall('dashcore');
        $home = collect(app(HostRuntime::class)->describe()['workers'])->pluck('label')->all();

        $this->asInstall('acme');
        $acme = collect(app(HostRuntime::class)->describe()['workers'])->pluck('label')->all();

        $this->assertSame(['com.dashcore.queue-tests', 'com.dashcore.queue-tests-2', 'com.dashcore.queue-tests-prs', 'com.dashcore.simulation-tests'], $home);
        // Another fleet never scans pull requests, so it has no PR worker.
        $this->assertSame(['com.dashcore.queue-tests.acme', 'com.dashcore.queue-tests.acme-2', 'com.dashcore.simulation-tests.acme'], $acme);
    }

    public function test_installs_share_the_hosts_scan_slots_and_pr_scans_keep_their_own(): void
    {
        config(['scanner.slots.scans' => 2, 'scanner.slots.pull_requests' => 1]);

        $dashcore = $this->worker('slots_a');
        $acme = $this->worker('slots_b');
        $sarah = $this->worker('slots_c');

        $this->assertSame(1, $dashcore->acquire());
        $this->assertSame(1, $dashcore->acquire(), 'a worker holds one slot, however often it asks');
        $this->assertSame(2, $acme->acquire());
        $this->assertNull($sarah->acquire(), 'two suites at once, host-wide');

        $this->assertSame(1, $sarah->acquire(pullRequest: true), 'a PR scan has its own slot');
        $sarah->release();

        $dashcore->release();
        $this->assertSame(1, $sarah->acquire());
    }

    public function test_a_scan_with_no_free_slot_waits_in_the_queue_without_running(): void
    {
        Queue::fake();
        config(['scanner.slots.scans' => 1]);
        $this->worker('slots_a')->acquire();

        $scan = Scan::factory()->create();
        $scanner = Mockery::mock(Scanner::class);
        $scanner->shouldNotReceive('run');

        (new RunScan($scan))->handle($scanner, app(PullRequestStatus::class), $this->worker('slots_b'));

        $this->assertSame(ScanStatus::Queued, $scan->fresh()->status);
        Queue::assertPushed(RunScan::class, fn (RunScan $job) => $job->scan->is($scan) && $job->delay !== null);
    }

    public function test_a_scan_runs_holding_a_slot_and_lets_it_go_after(): void
    {
        config(['scanner.slots.scans' => 1]);
        $scan = Scan::factory()->create();
        $slots = $this->worker('slots_a');

        $scanner = Mockery::mock(Scanner::class);
        $scanner->shouldReceive('run')->once()->andReturnUsing(function (Scan $scan) {
            $this->assertNull($this->worker('slots_b')->acquire(), 'the slot is held while the suite runs');

            return tap($scan)->update(['status' => ScanStatus::Completed]);
        });

        (new RunScan($scan))->handle($scanner, app(PullRequestStatus::class), $slots);

        $this->assertSame(1, $this->worker('slots_c')->acquire());
    }

    public function test_the_nightly_scan_starts_at_the_installs_own_time(): void
    {
        $this->assertSame('0 1 * * *', $this->nightlyScan()->expression);

        config(['scanner.nightly_at' => '01:20']);
        $this->assertSame('20 1 * * *', $this->nightlyScan()->expression);
    }

    public function test_host_runtime_prints_the_workers_and_schedule_the_install_needs(): void
    {
        $this->asInstall('acme');
        config(['scanner.nightly_at' => '01:40', 'scanner.nightly' => true, 'scanner.pull_requests.enabled' => false]);

        Artisan::call('tests:host-runtime', ['--json' => true]);
        $runtime = json_decode(Artisan::output(), true);

        $this->assertSame(['fleet' => 'acme', 'host' => 'tests.acme.practice.dashcore.com', 'home' => false, 'path' => base_path()], $runtime['install']);
        $this->assertSame(['com.dashcore.queue-tests.acme', 'com.dashcore.queue-tests.acme-2', 'com.dashcore.simulation-tests.acme'], array_column($runtime['workers'], 'label'));
        $this->assertSame([config('scanner.php'), 'artisan', 'queue:work', 'scans', '--queue=scans,builds'], array_slice($runtime['workers'][0]['program_arguments'], 0, 5));
        $this->assertSame(base_path(), $runtime['workers'][0]['working_directory']);
        $this->assertSame('01:40', $runtime['schedule']['nightly_scan_at']);
        $this->assertTrue($runtime['schedule']['nightly_scan']);
        $this->assertSame([config('scanner.php'), 'artisan', 'schedule:run'], $runtime['schedule']['program_arguments']);
        $this->assertSame('tests_scan_acme__', $runtime['scratch']['database_prefix']);
        $this->assertSame(2, $runtime['slots']['scans']);
    }

    /** The schedule as routes/console.php defines it under the current config. */
    private function nightlyScan(): Event
    {
        $schedule = new Schedule;
        $this->app->instance(Schedule::class, $schedule);
        Facade::clearResolvedInstance(Schedule::class);

        require base_path('routes/console.php');

        return collect($schedule->events())->first(fn (Event $event) => str_contains((string) $event->command, 'tests:scan') && ! str_contains((string) $event->command, 'tests:scan-prs'));
    }
}
