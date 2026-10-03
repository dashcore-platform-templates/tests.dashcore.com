<?php

namespace Tests\Feature\Scanning;

use App\Enums\MutationResult;
use App\Enums\ScanStatus;
use App\Enums\SurfaceChange;
use App\Enums\SurfaceKind;
use App\Enums\SurfaceStatus;
use App\Enums\VerdictReason;
use App\Jobs\RunScan;
use App\Models\FleetApp;
use App\Models\Scan;
use App\Scanning\Inventory\DiscoveredSurface;
use App\Scanning\Mutation\MutationTester;
use App\Scanning\Scanner;
use App\Scanning\SuiteRunner;
use App\Scanning\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
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

    /** @var array<string, MutationResult> verdicts the fake mutation tester returns, by identifier */
    public array $mutationVerdicts = [];

    /** @var list<string> identifiers the fake mutation tester was asked to check */
    public array $mutated = [];

    /** The main commit a pull request merges onto. */
    public string $prBase = '';

    /** @var list<array<string, mixed>> what a plain re-run of failing tests reports */
    public array $recheck = [];

    /** @var list<list<string>> */
    public array $rechecked = [];

    private FleetApp $fleetApp;

    protected function setUp(): void
    {
        parent::setUp();

        $root = $this->scannerRootWith(['finance.dashcore.com']);
        $this->fleetApp = FleetApp::factory()->create(['key' => 'finance', 'host' => 'finance.dashcore.com', 'local_path' => $root.'/finance.dashcore.com']);
        config(['scanner.workspace' => $root.'/_workspace']);

        $test = $this;

        // The workspace is the sample app fixture: no git, no composer, no database.
        $this->app->bind(Workspace::class, fn ($app, array $params) => new class($params['app'], $params['for'], $test) extends Workspace
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

            public function resolvePullRequest(int $number): array
            {
                return ['ref' => "pull/{$number}/merge", 'sha' => str_repeat('c', 40), 'subject' => 'Add exports', 'base' => $this->test->prBase];
            }

            public function prepare(string $sha, ?string $branch = null): void
            {
                $this->notes[] = 'npm run build failed, so views that load Vite assets may fail: x';
            }

            public function cleanup(bool $keepBranch = false): void
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

            public function runFiles(Workspace $workspace, array $files, string $name, int $timeout, bool $coverage = false): array
            {
                $this->test->rechecked[] = $files;
                $probe = $workspace->runDir.'/'.$name.'.ndjson';
                file_put_contents($probe, implode("\n", array_map('json_encode', $this->test->recheck)));

                return ['exit_code' => 0, 'timed_out' => false, 'probe' => $probe, 'output' => ''];
            }
        });

        $this->app->bind(MutationTester::class, fn () => new class($test) extends MutationTester
        {
            public function __construct(private readonly ScannerTest $test) {}

            public function test(Workspace $workspace, DiscoveredSurface $surface, array $proving): array
            {
                $this->test->mutated[] = $surface->identifier;
                $result = $this->test->mutationVerdicts[$surface->identifier] ?? MutationResult::Killed;

                return ['result' => $result, 'mutants' => 1, 'killed' => $result === MutationResult::Killed ? 1 : 0, 'survivor' => null];
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

    /** Tests that prove the report controller and both job methods. */
    private function provingEverything(): array
    {
        return [
            ['id' => 'T::index', 'file' => 'tests/T.php', 'status' => 'passed', 'assertions' => 1, 'routes' => ['GET reports'],
                'lines' => ['app/Http/Controllers/ReportController.php' => [9], 'app/Jobs/SendReport.php' => [11, 17]]],
        ];
    }

    public function test_a_method_whose_tests_survive_a_mutant_is_weak_and_says_why(): void
    {
        $this->probe = $this->provingEverything();
        $this->mutationVerdicts = ['App\Jobs\SendReport::handle' => MutationResult::Survived];

        $scan = $this->scan();

        $handle = $this->fleetApp->surfaces()->where('identifier', 'App\Jobs\SendReport::handle')->sole();
        $this->assertSame(SurfaceStatus::Weak, $handle->status);
        $this->assertSame(VerdictReason::MutantSurvived, $handle->reason);
        $this->assertSame('survived', $handle->mutation);
        $this->assertNotNull($handle->mutation_checked_at);

        $failed = $this->fleetApp->surfaces()->where('identifier', 'App\Jobs\SendReport::failed')->sole();
        $this->assertSame(SurfaceStatus::Tested, $failed->status);
        $this->assertSame('killed', $failed->mutation);

        $this->assertSame(1, $scan->mutants_survived);
        $this->assertSame(2, $scan->mutants_killed);
        $this->assertSame(1, $scan->surfaces_weak);
        $this->assertSame(MutationResult::Survived, $scan->surfaceResults()->where('surface_id', $handle->id)->sole()->mutation);
        $this->assertEqualsCanonicalizing(['App\Jobs\SendReport::handle', 'App\Jobs\SendReport::failed', 'App\Http\Controllers\ReportController::index'], $this->mutated, 'routes have no body to mutate');
    }

    public function test_verdicts_are_kept_while_the_code_is_unchanged_and_survivors_are_rechecked_first(): void
    {
        $this->probe = $this->provingEverything();
        $this->mutationVerdicts = ['App\Jobs\SendReport::handle' => MutationResult::Survived];
        $this->scan();

        // Nothing changed: kills are reused, the survivor is checked again — and now dies.
        $this->mutated = [];
        $this->mutationVerdicts = [];
        $second = $this->scan();

        $this->assertSame(['App\Jobs\SendReport::handle'], $this->mutated);
        $this->assertSame(SurfaceStatus::Tested, $this->fleetApp->surfaces()->where('identifier', 'App\Jobs\SendReport::handle')->sole()->status);
        $this->assertSame(3, $second->mutants_killed, 'reused kills still count');

        // A changed method is checked again; an old kill is re-checked once it ages out.
        $this->mutated = [];
        $this->fleetApp->surfaces()->where('identifier', 'App\Jobs\SendReport::failed')->update(['mutation_hash' => 'stale']);
        $this->fleetApp->surfaces()->where('identifier', 'App\Http\Controllers\ReportController::index')->update(['mutation_checked_at' => now()->subDays(31)]);
        $this->scan();

        $this->assertSame(['App\Jobs\SendReport::failed', 'App\Http\Controllers\ReportController::index'], $this->mutated, 'changed before aged');
    }

    public function test_the_budget_caps_mutation_checks_and_says_how_many_wait(): void
    {
        config(['scanner.mutation.budget' => 1]);
        $this->probe = $this->provingEverything();

        $scan = $this->scan();

        $this->assertCount(1, $this->mutated);
        $this->assertContains('2 tested method(s) are still waiting for a mutation check; the budget is 1 per scan.', $scan->notes);
    }

    public function test_a_survivor_not_rechecked_this_scan_stays_weak(): void
    {
        $this->probe = $this->provingEverything();
        $this->mutationVerdicts = ['App\Jobs\SendReport::handle' => MutationResult::Survived];
        $this->scan();

        config(['scanner.mutation.budget' => 0]);
        $this->scan();

        $this->assertSame(SurfaceStatus::Weak, $this->fleetApp->surfaces()->where('identifier', 'App\Jobs\SendReport::handle')->sole()->status);
    }

    public function test_the_ratchet_flags_lost_coverage_and_new_untested_surfaces_against_the_last_scan(): void
    {
        $this->probe = $this->provingEverything();
        $first = $this->scan();

        $this->assertNull($first->previous_scan_id);
        $this->assertSame(0, $first->regressions(), 'the first scan is the baseline');

        // Reports lose their test; a new route arrives untested; POST reports gains one.
        $this->probe = [['id' => 'T::store', 'file' => 'tests/T.php', 'status' => 'passed', 'assertions' => 1, 'routes' => ['POST reports']]];
        Process::fake(['*route:list*' => Process::result(json_encode([
            ['method' => 'GET|HEAD', 'uri' => 'reports', 'middleware' => []],
            ['method' => 'POST', 'uri' => 'reports', 'middleware' => []],
            ['method' => 'GET|HEAD', 'uri' => 'exports', 'middleware' => []],
        ]))]);

        $second = $this->scan();
        $change = fn (string $identifier) => $second->surfaceResults()->whereHas('surface', fn ($q) => $q->where('identifier', $identifier))->sole()->change;

        $this->assertSame($first->id, $second->previous_scan_id);
        $this->assertSame(SurfaceChange::Lost, $change('GET reports'));
        $this->assertSame(SurfaceChange::NewUntested, $change('GET exports'));
        $this->assertSame(SurfaceChange::Gained, $change('POST reports'));
        $this->assertNull($change('App\Models\Invoice::total'), 'untested before and after is not news');
        $this->assertSame(1, $second->surfaces_new_untested);
        $this->assertSame(1, $second->surfaces_gained);
        $this->assertGreaterThanOrEqual(1, $second->surfaces_lost);
        $this->assertSame($second->surfaces_new_untested + $second->surfaces_lost, $second->regressions());
    }

    public function test_a_test_that_fails_only_under_coverage_is_recorded_as_passing_and_noted(): void
    {
        $this->probe = [
            ['id' => 'Perf::fast', 'file' => 'tests/PerfTest.php', 'name' => 'projects quickly', 'status' => 'failed', 'message' => '7872 is not less than 2000', 'assertions' => 1, 'routes' => ['GET reports']],
            ['id' => 'Real::broken', 'file' => 'tests/RealTest.php', 'name' => 'really broken', 'status' => 'failed', 'assertions' => 1],
        ];
        $this->recheck = [
            ['id' => 'Perf::fast', 'status' => 'passed', 'assertions' => 2],
            ['id' => 'Real::broken', 'status' => 'failed', 'assertions' => 1],
        ];

        $scan = $this->scan();

        $this->assertEqualsCanonicalizing(['tests/PerfTest.php', 'tests/RealTest.php'], $this->rechecked[0]);
        $this->assertSame(1, $scan->tests_failed, 'only the real failure is left');
        $this->assertSame('passed', $scan->tests()->where('test_id', 'Perf::fast')->sole()->outcome->value);
        $this->assertSame(SurfaceStatus::Tested, $this->fleetApp->surfaces()->where('identifier', 'GET reports')->sole()->status, 'what it executed still counts');
        $this->assertContains('1 test(s) failed only under coverage and passed when run again without it (timing or flakiness): projects quickly', $scan->notes);
    }

    private function pullRequestScan(): Scan
    {
        $scan = $this->fleetApp->scans()->create([
            'kind' => 'pull_request', 'status' => ScanStatus::Queued,
            'pr_number' => 5, 'pr_head_sha' => str_repeat('d', 40), 'pr_url' => 'https://github.com/x/finance/pull/5',
        ]);

        return app(Scanner::class)->run($scan);
    }

    public function test_a_pull_request_is_judged_against_main_and_changes_nothing_in_the_record(): void
    {
        $this->probe = $this->provingEverything();
        $main = $this->scan();
        $this->prBase = $main->commit_sha;
        $recordBefore = $this->fleetApp->surfaces()->orderBy('id')->get(['id', 'status', 'last_scan_id'])->toArray();

        // The PR drops the test that reached the reports route and controller.
        $this->probe = [['id' => 'T::jobs', 'file' => 'tests/T.php', 'status' => 'passed', 'assertions' => 1, 'lines' => ['app/Jobs/SendReport.php' => [11, 17]]]];
        $this->mutated = [];

        $pr = $this->pullRequestScan();

        $this->assertSame(ScanStatus::Completed, $pr->status, (string) $pr->error);
        $this->assertSame($main->id, $pr->base_scan_id);
        $this->assertSame('fail', $pr->verdict);
        $this->assertContains('Routes: GET /reports', $pr->verdict_detail['lost']);
        $this->assertContains('Controllers: ReportController::index', $pr->verdict_detail['lost']);
        $this->assertSame([], $this->mutated, 'a pull request waits for no new mutants');
        $this->assertSame($recordBefore, $this->fleetApp->surfaces()->orderBy('id')->get(['id', 'status', 'last_scan_id'])->toArray(), 'the app\'s record is untouched');
        $this->assertSame(0, $pr->surfaceResults()->count());
        $this->assertTrue($this->fleetApp->fresh()->latestCompletedScan->is($main), 'a PR scan never stands in for main');
        $this->assertSame('tests_scan_finance_pr5', $pr->test_database);
    }

    public function test_a_pull_request_whose_base_was_never_scanned_waits_for_it(): void
    {
        Queue::fake();
        $this->probe = $this->provingEverything();
        $this->scan();
        $this->prBase = str_repeat('e', 40);

        $pr = $this->pullRequestScan();

        $this->assertSame(ScanStatus::Queued, $pr->status);
        $this->assertSame(1, $pr->deferrals);
        $this->assertStringContainsString('Waiting for main at eeeeeee', $pr->notes[0]);
        Queue::assertPushed(RunScan::class, fn (RunScan $job) => ! $job->scan->isPullRequest());
    }

    public function test_after_waiting_long_enough_it_settles_for_the_latest_main_scan_and_says_so(): void
    {
        $this->probe = $this->provingEverything();
        $main = $this->scan();
        $this->prBase = str_repeat('e', 40);
        config(['scanner.pull_requests.max_deferrals' => 0]);

        $pr = $this->pullRequestScan();

        $this->assertSame(ScanStatus::Completed, $pr->status, (string) $pr->error);
        $this->assertSame($main->id, $pr->base_scan_id);
        $this->assertSame('pass', $pr->verdict);
        $this->assertStringContainsString('was never scanned, so this was compared with the latest main scan', implode("\n", $pr->notes));
    }
}
