<?php

namespace Tests\Feature\Scanning;

use App\Enums\ScanStatus;
use App\Enums\SurfaceKind;
use App\Enums\SurfaceStatus;
use App\Models\FleetApp;
use App\Models\Scan;
use App\Scanning\Scanner;
use App\Scanning\SuiteRunner;
use App\Scanning\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\Concerns\UsesFleetFixtures;
use Tests\TestCase;

class ScannerTest extends TestCase
{
    use RefreshDatabase, UsesFleetFixtures;

    /** @var list<array<string, mixed>> */
    private array $probe = [];

    private ?\Throwable $runnerFails = null;

    public bool $cleanedUp = false;

    private FleetApp $fleetApp;

    protected function setUp(): void
    {
        parent::setUp();

        $root = $this->scannerRootWith(['finance.dashcore.com']);
        $this->fleetApp = FleetApp::factory()->create(['key' => 'finance', 'host' => 'finance.dashcore.com', 'local_path' => $root.'/finance.dashcore.com']);
        config(['scanner.workspace' => $root.'/_workspace']);

        $test = $this;

        // The workspace is the sample app fixture: no git, no composer, no database.
        $this->app->bind(Workspace::class, fn ($app, array $params) => new class($params['app'], $params['scan'], $test) extends Workspace
        {
            public function __construct(FleetApp $app, Scan $scan, private readonly ScannerTest $test)
            {
                parent::__construct($app, $scan);
                $this->path = dirname(__DIR__, 2).'/Fixtures/sample-app';
                @mkdir($this->runDir, 0777, true);
            }

            public function resolveRevision(): array
            {
                return ['ref' => 'origin/main', 'sha' => str_repeat('a', 40), 'subject' => 'Add reports'];
            }

            public function prepare(string $sha): void
            {
                $this->notes[] = 'npm run build failed, so views that load Vite assets may fail: x';
            }

            public function cleanup(): void
            {
                $this->test->cleanedUp = true;
            }

            public function environment(): array
            {
                return ['APP_ENV' => 'testing'];
            }
        });

        $this->app->bind(SuiteRunner::class, fn () => new class($test) extends SuiteRunner
        {
            public function __construct(private readonly ScannerTest $test) {}

            public function run(Workspace $workspace): array
            {
                return $this->test->runSuite($workspace);
            }
        });

        Process::fake([
            '*route:list*' => Process::result(json_encode([
                ['method' => 'GET|HEAD', 'uri' => 'reports', 'name' => 'reports', 'action' => 'ReportController@index', 'middleware' => ['web']],
                ['method' => 'POST', 'uri' => 'reports', 'name' => null, 'action' => 'ReportController@store', 'middleware' => ['web']],
            ])),
        ]);
    }

    /** @internal called by the fake runner */
    public function runSuite(Workspace $workspace): array
    {
        if ($this->runnerFails) {
            throw $this->runnerFails;
        }

        $file = $workspace->runDir.'/probe.ndjson';
        file_put_contents($file, implode("\n", array_map('json_encode', $this->probe))."\n");

        return ['framework' => 'pest', 'exit_code' => 1, 'output' => 'Tests: 1 failed, 2 passed', 'probe' => $file, 'junit' => ''];
    }

    private function scan(): Scan
    {
        return app(Scanner::class)->run($this->fleetApp->scans()->create(['status' => ScanStatus::Queued]));
    }

    public function test_a_scan_records_every_surface_with_its_verdict_and_the_suite_with_it(): void
    {
        $this->probe = [
            ['id' => 'ReportsTest::index', 'file' => 'tests/ReportsTest.php', 'name' => 'lists reports', 'status' => 'passed', 'assertions' => 2, 'duration_ms' => 40,
                'routes' => ['GET reports'], 'lines' => ['app/Http/Controllers/ReportController.php' => [9]]],
            ['id' => 'JobTest::handle', 'file' => 'tests/JobTest.php', 'name' => 'sends', 'status' => 'passed', 'assertions' => 0,
                'lines' => ['app/Jobs/SendReport.php' => [11, 12]]],
            ['id' => 'SettingsTest::save', 'file' => 'tests/SettingsTest.php', 'name' => 'saves', 'status' => 'failed', 'message' => 'Expected 200', 'assertions' => 1,
                'mounts' => ['settings'], 'mount_classes' => ['App\Livewire\Settings\Index'], 'calls' => ['settings@save'], 'call_classes' => ['App\Livewire\Settings\Index@save']],
        ];

        $scan = $this->scan();

        $this->assertSame(ScanStatus::Completed, $scan->status, (string) $scan->error);
        $this->assertTrue($this->cleanedUp);
        $this->assertSame(str_repeat('a', 40), $scan->commit_sha);
        $this->assertSame('pest', $scan->framework);
        $this->assertSame(1, $scan->exit_code);
        $this->assertSame([3, 2, 1, 0], [$scan->tests_total, $scan->tests_passed, $scan->tests_failed, $scan->tests_risky]);
        $this->assertContains('npm run build failed, so views that load Vite assets may fail: x', $scan->notes);
        $this->assertStringContainsString('Could not parse app/Services/Broken.php', implode("\n", $scan->notes));

        $surface = fn (SurfaceKind $kind, string $identifier) => $this->fleetApp->surfaces()->where('kind', $kind)->where('identifier', $identifier)->sole();

        $this->assertSame(SurfaceStatus::Tested, $surface(SurfaceKind::Route, 'GET reports')->status);
        $this->assertSame(SurfaceStatus::Missing, $surface(SurfaceKind::Route, 'POST reports')->status);
        $this->assertSame(SurfaceStatus::Tested, $surface(SurfaceKind::Controller, 'App\Http\Controllers\ReportController::index')->status);
        $this->assertSame(SurfaceStatus::Weak, $surface(SurfaceKind::Job, 'App\Jobs\SendReport::handle')->status, 'no assertions');
        $this->assertSame(SurfaceStatus::Weak, $surface(SurfaceKind::Action, 'App\Livewire\Settings\Index::save')->status, 'only a failing test');
        $this->assertSame(SurfaceStatus::Missing, $surface(SurfaceKind::Job, 'App\Jobs\SendReport::failed')->status);

        $this->assertSame($scan->surfaces_total, $scan->surfaces_tested + $scan->surfaces_weak + $scan->surfaces_missing);
        $this->assertSame($this->fleetApp->surfaces()->count(), $scan->surfaces_total);
        $this->assertEquals(round($scan->surfaces_tested / $scan->surfaces_total * 100, 2), (float) $scan->score);

        $evidence = $scan->surfaceResults()->where('surface_id', $surface(SurfaceKind::Route, 'GET reports')->id)->sole();
        $this->assertSame(1, $evidence->tests_count);
        $this->assertSame([['id' => 'ReportsTest::index', 'name' => 'lists reports', 'file' => 'tests/ReportsTest.php', 'outcome' => 'passed']], $evidence->evidence);

        $failing = $scan->tests()->where('outcome', 'failed')->sole();
        $this->assertSame('Expected 200', $failing->message);
        $this->assertSame(2, $failing->surfaces_count, 'the settings component and its save action');
    }

    public function test_a_rescan_retires_surfaces_that_are_gone_and_updates_the_rest(): void
    {
        $this->scan();
        $gone = $this->fleetApp->surfaces()->create(['kind' => SurfaceKind::Route, 'identifier' => 'GET old', 'label' => 'GET /old', 'status' => SurfaceStatus::Missing]);
        $this->probe = [['id' => 'T::a', 'status' => 'passed', 'assertions' => 1, 'routes' => ['POST reports']]];

        $second = $this->scan();

        $this->assertNotNull($gone->fresh()->removed_at);
        $this->assertNull($gone->fresh()->status);
        $this->assertSame(SurfaceStatus::Tested, $this->fleetApp->surfaces()->where('identifier', 'POST reports')->sole()->status);
        $this->assertSame($second->id, $this->fleetApp->surfaces()->where('identifier', 'POST reports')->sole()->last_scan_id);
        $this->assertSame(0, $second->surfaceResults()->where('surface_id', $gone->id)->count());
    }

    public function test_a_suite_that_produces_nothing_fails_the_scan_and_still_cleans_up(): void
    {
        $this->runnerFails = new RuntimeException('The suite produced no test results (exit 255). PHP Fatal error');

        $scan = $this->scan();

        $this->assertSame(ScanStatus::Failed, $scan->status);
        $this->assertStringContainsString('PHP Fatal error', $scan->error);
        $this->assertNotNull($scan->finished_at);
        $this->assertTrue($this->cleanedUp);
        $this->assertContains('npm run build failed, so views that load Vite assets may fail: x', $scan->notes);
        $this->assertSame(0, $this->fleetApp->surfaces()->count());
    }

    public function test_an_app_without_a_checkout_fails_without_touching_anything(): void
    {
        $this->fleetApp->update(['local_path' => null]);

        $scan = $this->scan();

        $this->assertSame(ScanStatus::Failed, $scan->status);
        $this->assertStringContainsString('no checkout of finance.dashcore.com', $scan->error);
        $this->assertFalse($this->cleanedUp, 'no workspace was ever created');
    }
}
