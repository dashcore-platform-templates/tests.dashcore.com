<?php

namespace Tests\Feature\Scanning;

use App\Enums\MutationResult;
use App\Enums\SurfaceKind;
use App\Enums\TestOutcome;
use App\Models\FleetApp;
use App\Models\Scan;
use App\Scanning\Evidence\TestRecord;
use App\Scanning\Inventory\DiscoveredSurface;
use App\Scanning\Mutation\MutationTester;
use App\Scanning\SuiteRunner;
use App\Scanning\Workspace;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MutationTesterTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private string $original = "<?php\nclass Pricing\n{\n    public function total(): int\n    {\n        return 42;\n    }\n}\n";

    /** @var Closure(string): array{outcomes: array<string, string>, timed_out?: bool} */
    private Closure $suite;

    /** @var list<array{files: list<string>, source: string}> */
    public array $runs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $dir = realpath(sys_get_temp_dir()).'/mutation-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir.'/app');
        file_put_contents($dir.'/app/Pricing.php', $this->original);

        $app = FleetApp::factory()->create();
        $this->workspace = new Workspace($app, Scan::factory()->for($app)->create());
        $this->workspace->path = $dir;
        $this->workspace->runDir = $dir.'/_run';
        File::ensureDirectoryExists($this->workspace->runDir);

        $test = $this;
        $this->app->instance(SuiteRunner::class, new class($test) extends SuiteRunner
        {
            public function __construct(private readonly MutationTesterTest $test) {}

            public function runFiles(Workspace $workspace, array $files, string $name, int $timeout): array
            {
                return $this->test->fakeRun($workspace, $files, $name);
            }
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace->path);

        parent::tearDown();
    }

    /** @internal */
    public function fakeRun(Workspace $workspace, array $files, string $name): array
    {
        $source = file_get_contents($workspace->path.'/app/Pricing.php');
        $this->runs[] = ['files' => $files, 'source' => $source];
        $result = ($this->suite)($source);

        $probe = $workspace->runDir.'/'.$name.'.ndjson';
        file_put_contents($probe, implode("\n", array_map(
            fn (string $id, string $status) => json_encode(['id' => $id, 'status' => $status, 'assertions' => 1]),
            array_keys($result['outcomes']),
            $result['outcomes'],
        )));

        return ['exit_code' => 0, 'timed_out' => $result['timed_out'] ?? false, 'probe' => $probe, 'output' => ''];
    }

    private function surface(): DiscoveredSurface
    {
        return new DiscoveredSurface(SurfaceKind::Service, 'Pricing::total', 'Pricing::total', 'app/Pricing.php', 6, 7, hash: 'h');
    }

    private function proving(string ...$ids): array
    {
        return array_map(fn (string $id) => new TestRecord($id, 'tests/'.explode('::', $id)[0].'.php', $id, TestOutcome::Passed, 1, 1, null, [], []), $ids);
    }

    public function test_a_mutant_a_proving_test_notices_is_killed(): void
    {
        $this->suite = fn (string $source) => ['outcomes' => [
            'PricingTest::a' => 'passed',
            'PricingTest::b' => str_contains($source, 'return 42;') ? 'passed' : 'failed',
        ]];

        $result = app(MutationTester::class)->test($this->workspace, $this->surface(), $this->proving('PricingTest::a', 'PricingTest::b'));

        $this->assertSame(MutationResult::Killed, $result['result']);
        $this->assertSame(2, $result['mutants']);
        $this->assertSame(2, $result['killed']);
        $this->assertSame(['tests/PricingTest.php'], $this->runs[0]['files'], 'only the proving tests\' files run');
        $this->assertStringContainsString('return 0;', $this->runs[0]['source']);
        $this->assertStringContainsString('return 1;', $this->runs[1]['source']);
        $this->assertSame($this->original, file_get_contents($this->workspace->path.'/app/Pricing.php'), 'the original is put back');
    }

    public function test_one_mutant_the_suite_misses_means_the_method_survives(): void
    {
        // The tests notice 0 but not 1.
        $this->suite = fn (string $source) => ['outcomes' => ['PricingTest::a' => str_contains($source, 'return 0;') ? 'failed' : 'passed']];

        $result = app(MutationTester::class)->test($this->workspace, $this->surface(), $this->proving('PricingTest::a'));

        $this->assertSame(MutationResult::Survived, $result['result']);
        $this->assertSame(1, $result['killed']);
        $this->assertSame('returns 1', $result['survivor']);
    }

    public function test_a_test_turning_risky_or_skipped_does_not_kill(): void
    {
        $this->suite = fn () => ['outcomes' => ['PricingTest::a' => 'risky', 'PricingTest::b' => 'skipped']];

        $result = app(MutationTester::class)->test($this->workspace, $this->surface(), $this->proving('PricingTest::a', 'PricingTest::b'));

        $this->assertSame(MutationResult::Survived, $result['result']);
    }

    public function test_a_timeout_or_a_test_that_never_reported_is_the_suite_noticing(): void
    {
        $this->suite = fn () => ['outcomes' => ['PricingTest::a' => 'passed'], 'timed_out' => true];
        $this->assertSame(MutationResult::Killed, app(MutationTester::class)->test($this->workspace, $this->surface(), $this->proving('PricingTest::a'))['result']);

        $this->suite = fn () => ['outcomes' => ['PricingTest::a' => 'passed']];
        $this->assertSame(MutationResult::Killed, app(MutationTester::class)->test($this->workspace, $this->surface(), $this->proving('PricingTest::a', 'PricingTest::gone'))['result']);
    }

    public function test_a_run_where_no_test_reported_at_all_concludes_nothing(): void
    {
        $this->suite = fn () => ['outcomes' => []];

        $result = app(MutationTester::class)->test($this->workspace, $this->surface(), $this->proving('PricingTest::a'));

        $this->assertSame(MutationResult::Error, $result['result']);
        $this->assertSame($this->original, file_get_contents($this->workspace->path.'/app/Pricing.php'));
    }

    public function test_nothing_to_mutate_or_nothing_proving_it_concludes_nothing(): void
    {
        $this->suite = fn () => ['outcomes' => ['PricingTest::a' => 'passed']];
        $tester = app(MutationTester::class);

        $this->assertSame(MutationResult::Error, $tester->test($this->workspace, $this->surface(), [])['result']);

        $wrongLine = new DiscoveredSurface(SurfaceKind::Service, 'x', 'x', 'app/Pricing.php', 2, 3, hash: 'h');
        $this->assertSame(MutationResult::Error, $tester->test($this->workspace, $wrongLine, $this->proving('PricingTest::a'))['result']);
        $this->assertSame([], $this->runs);
    }
}
