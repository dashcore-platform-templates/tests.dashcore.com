<?php

namespace Tests\Feature\Building;

use App\Building\BuildVerifier;
use App\Enums\MutationResult;
use App\Enums\SurfaceKind;
use App\Enums\TestOutcome;
use App\Models\Build;
use App\Models\FleetApp;
use App\Models\Scan;
use App\Models\Surface;
use App\Scanning\Inventory\DiscoveredSurface;
use App\Scanning\Inventory\Inventory;
use App\Scanning\Mutation\MutationTester;
use App\Scanning\SuiteRunner;
use App\Scanning\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class BuildVerifierTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private Workspace $workspace;

    private Build $build;

    private Surface $total;

    private Surface $route;

    /** @var array<string, list<array<string, mixed>>> probe rows per run name */
    public array $runs = [];

    public MutationResult $mutation = MutationResult::Killed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = realpath(sys_get_temp_dir()).'/verify-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->dir.'/app/Services');
        File::ensureDirectoryExists($this->dir.'/tests/Feature');
        file_put_contents($this->dir.'/app/Services/Pricing.php', "<?php // original\n");
        file_put_contents($this->dir.'/phpunit.xml', "<phpunit/>\n");
        $git = fn (string ...$args) => Process::path($this->dir)->run(['git', ...$args])->throw();
        $git('init', '--quiet');
        $git('add', '.');
        $git('-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '--quiet', '-m', 'base');

        $app = FleetApp::factory()->create();
        $scan = Scan::factory()->completed()->for($app)->create();
        $scan->tests()->create(['test_id' => 'Old::already_broken', 'name' => 'already broken', 'outcome' => TestOutcome::Failed]);

        $this->total = $app->surfaces()->create(['kind' => SurfaceKind::Service, 'identifier' => 'App\Services\Pricing::total', 'label' => 'Pricing::total']);
        $this->route = $app->surfaces()->create(['kind' => SurfaceKind::Route, 'identifier' => 'GET reports', 'label' => 'GET /reports']);
        $this->build = $app->builds()->create(['status' => 'verifying', 'targets' => [$this->total->id, $this->route->id], 'scan_id' => $scan->id, 'base_sha' => $scan->commit_sha]);

        $this->workspace = new Workspace($app, $this->build);
        $this->workspace->path = $this->dir;
        $this->workspace->runDir = $this->dir.'/../'.basename($this->dir).'-run';
        File::ensureDirectoryExists($this->workspace->runDir);

        // The workspace's own rewrite of phpunit.xml is not the agent's change.
        file_put_contents($this->dir.'/phpunit.xml', "<phpunit><!-- scratch db --></phpunit>\n");
        $this->workspace->rewritten['phpunit.xml'] = sha1_file($this->dir.'/phpunit.xml');

        $test = $this;

        $this->app->instance(SuiteRunner::class, new class($test) extends SuiteRunner
        {
            public function __construct(private readonly BuildVerifierTest $test) {}

            public function runFiles(Workspace $workspace, array $files, string $name, int $timeout, bool $coverage = false): array
            {
                $probe = $workspace->runDir.'/'.$name.'.ndjson';
                file_put_contents($probe, implode("\n", array_map('json_encode', $this->test->runs[$name] ?? [])));

                return ['exit_code' => 0, 'timed_out' => false, 'probe' => $probe, 'output' => 'suite output'];
            }

            public function php(): string
            {
                return 'php';
            }
        });

        $this->app->instance(Inventory::class, new class extends Inventory
        {
            public function __construct() {}

            public function discover(string $root, string $php, array $environment): array
            {
                return [
                    new DiscoveredSurface(SurfaceKind::Service, 'App\Services\Pricing::total', 'Pricing::total', 'app/Services/Pricing.php', 5, 9, hash: 'h'),
                    new DiscoveredSurface(SurfaceKind::Route, 'GET reports', 'GET /reports', match: ['GET reports']),
                ];
            }
        });

        $this->app->instance(MutationTester::class, new class($test) extends MutationTester
        {
            public function __construct(private readonly BuildVerifierTest $test) {}

            public function test(Workspace $workspace, DiscoveredSurface $surface, array $proving): array
            {
                return ['result' => $this->test->mutation, 'mutants' => 2, 'killed' => 2, 'survivor' => null];
            }
        });

        $this->runs = [
            'build-tests' => [
                ['id' => 'PricingTest::totals', 'file' => 'tests/Feature/PricingTest.php', 'name' => 'totals', 'status' => 'passed', 'assertions' => 2, 'lines' => ['app/Services/Pricing.php' => [6]]],
            ],
            'build-suite' => [
                ['id' => 'PricingTest::totals', 'status' => 'passed', 'assertions' => 2],
                ['id' => 'Old::already_broken', 'status' => 'failed', 'assertions' => 1],
            ],
        ];
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        File::deleteDirectory($this->workspace->runDir);

        parent::tearDown();
    }

    private function agentWrites(array $files): void
    {
        foreach ($files as $path => $contents) {
            File::ensureDirectoryExists(dirname($this->dir.'/'.$path));
            file_put_contents($this->dir.'/'.$path, $contents);
        }
    }

    private function verify(): array
    {
        $verifier = app(BuildVerifier::class);

        return $verifier->verify($this->build, $this->workspace, Surface::whereIn('id', $this->build->targets)->get(), $verifier->baselineFailures($this->build, $this->workspace));
    }

    public function test_good_tests_are_accepted_and_only_tests_and_factories_are_kept(): void
    {
        $this->agentWrites([
            'tests/Feature/PricingTest.php' => '<?php // test',
            'database/factories/InvoiceFactory.php' => '<?php // factory',
            'app/Services/Pricing.php' => "<?php // \"fixed\" by the agent\n",
            'config/sneaky.php' => '<?php return [];',
        ]);

        $report = $this->verify();

        $this->assertTrue($report['accepted'], implode("\n", $report['reasons']));
        $this->assertSame(['database/factories/InvoiceFactory.php', 'tests/Feature/PricingTest.php'], $report['files']);
        $this->assertEqualsCanonicalizing(['app/Services/Pricing.php', 'config/sneaky.php'], $report['reverted']);
        $this->assertSame("<?php // original\n", file_get_contents($this->dir.'/app/Services/Pricing.php'), 'app code is put back');
        $this->assertFileDoesNotExist($this->dir.'/config/sneaky.php');
        $this->assertSame(1, $report['tests']);

        $this->assertTrue($report['targets'][$this->total->id]['proven']);
        $this->assertSame('killed', $report['targets'][$this->total->id]['mutation']);
        $this->assertSame(['totals'], $report['targets'][$this->total->id]['tests']);
        $this->assertFalse($report['targets'][$this->route->id]['proven'], 'no new test hit the route');
        $this->assertSame('missing', $report['targets'][$this->route->id]['status']);
    }

    public function test_no_test_files_is_a_rejection(): void
    {
        $this->agentWrites(['app/Services/Pricing.php' => '<?php // only app code']);

        $report = $this->verify();

        $this->assertFalse($report['accepted']);
        $this->assertSame(['The agent wrote no test files.'], $report['reasons']);
    }

    public function test_new_tests_that_fail_or_assert_nothing_are_rejected(): void
    {
        $this->agentWrites(['tests/Feature/PricingTest.php' => '<?php // test']);
        $this->runs['build-tests'][] = ['id' => 'PricingTest::broken', 'name' => 'broken', 'status' => 'failed', 'message' => 'Expected 3', 'assertions' => 1];
        $this->runs['build-tests'][] = ['id' => 'PricingTest::hollow', 'name' => 'hollow', 'status' => 'passed', 'assertions' => 0];

        $report = $this->verify();

        $this->assertFalse($report['accepted']);
        $this->assertContains('A new test does not pass (failed): broken — Expected 3', $report['reasons']);
        $this->assertContains('A new test asserts nothing: hollow', $report['reasons']);
    }

    public function test_a_target_whose_mutant_survives_is_not_proven(): void
    {
        $this->agentWrites(['tests/Feature/PricingTest.php' => '<?php // test']);
        $this->mutation = MutationResult::Survived;

        $report = $this->verify();

        $this->assertFalse($report['targets'][$this->total->id]['proven']);
        $this->assertFalse($report['accepted']);
        $this->assertContains('None of the targets is proven by the new tests.', $report['reasons']);
    }

    public function test_a_new_failure_in_the_full_suite_is_a_rejection_but_an_old_one_is_not(): void
    {
        $this->agentWrites(['tests/Feature/PricingTest.php' => '<?php // test']);
        $this->runs['build-suite'][] = ['id' => 'Other::was_fine', 'file' => 'tests/Feature/OtherTest.php', 'name' => 'was fine', 'status' => 'errored', 'message' => 'Duplicate key', 'assertions' => 0];

        $report = $this->verify();

        $this->assertFalse($report['accepted']);
        $this->assertSame(['The full suite has a new failure: was fine (tests/Feature/OtherTest.php) — Duplicate key'], $report['reasons']);
    }

    public function test_a_new_failure_that_passes_when_run_again_is_noted_as_flaky_not_blamed(): void
    {
        $this->agentWrites(['tests/Feature/PricingTest.php' => '<?php // test']);
        $this->runs['build-suite'][] = ['id' => 'Other::flaky', 'file' => 'tests/Feature/OtherTest.php', 'name' => 'flaky', 'status' => 'failed', 'assertions' => 1];
        $this->runs['build-recheck'] = [['id' => 'Other::flaky', 'status' => 'passed', 'assertions' => 1]];

        $report = $this->verify();

        $this->assertTrue($report['accepted'], implode("\n", $report['reasons']));
        $this->assertSame(['Flaky: flaky (tests/Feature/OtherTest.php) failed in the full suite and passed when run again.'], $report['notes']);
    }

    public function test_without_a_scan_of_the_base_commit_the_base_is_measured_first(): void
    {
        $this->build->update(['base_sha' => str_repeat('f', 40)]);
        $this->runs['base-suite'] = [['id' => 'Other::was_broken', 'status' => 'failed', 'assertions' => 1]];

        $this->assertSame(['Other::was_broken' => true], app(BuildVerifier::class)->baselineFailures($this->build->fresh(), $this->workspace));
    }

    public function test_a_suite_that_will_not_run_is_a_rejection(): void
    {
        $this->agentWrites(['tests/Feature/PricingTest.php' => '<?php // test']);
        $this->runs['build-suite'] = [];

        $report = $this->verify();

        $this->assertFalse($report['accepted']);
        $this->assertStringContainsString('The full suite did not run with the change in it', $report['reasons'][0]);
    }
}
