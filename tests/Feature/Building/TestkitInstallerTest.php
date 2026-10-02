<?php

namespace Tests\Feature\Building;

use App\Building\BuildRequester;
use App\Building\Publisher;
use App\Building\TestkitInstaller;
use App\Enums\BuildKind;
use App\Enums\BuildStatus;
use App\Jobs\RunBuild;
use App\Models\Build;
use App\Models\FleetApp;
use App\Models\Scan;
use App\Scanning\SuiteRunner;
use App\Scanning\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\UsesFleetFixtures;
use Tests\TestCase;

class TestkitInstallerTest extends TestCase
{
    use RefreshDatabase, UsesFleetFixtures;

    private FleetApp $fleetApp;

    public string $dir;

    public string $committedDriver = 'pgsql';

    public string $lock = '{"packages": [], "packages-dev": []}';

    /** @var array<string, list<array<string, mixed>>> */
    public array $runs = [];

    public ?array $shipped = null;

    protected function setUp(): void
    {
        parent::setUp();

        $root = $this->scannerRootWith(['finance.dashcore.com']);
        File::ensureDirectoryExists($root.'/testkit/bin');
        file_put_contents($root.'/testkit/bin/vendor-into', '#!/bin/sh');
        config(['scanner.testkit.version' => 'v0.1.3']);

        $this->fleetApp = FleetApp::factory()->create(['key' => 'finance', 'name' => 'Finance', 'host' => 'finance.dashcore.com', 'local_path' => $root.'/finance.dashcore.com']);
        Scan::factory()->completed()->for($this->fleetApp)->create();
        $this->dir = $root.'/_worktree';

        $test = $this;

        $this->app->bind(Workspace::class, fn ($app, array $params) => new class($params['app'], $params['for'], $test) extends Workspace
        {
            public function __construct(FleetApp $app, Scan|Build $for, private readonly TestkitInstallerTest $test)
            {
                parent::__construct($app, $for);
                $this->path = $test->dir;
                $this->runDir = $test->dir.'-run';
            }

            public function resolveRevision(): array
            {
                return ['ref' => 'origin/main', 'sha' => str_repeat('d', 40), 'subject' => 'x'];
            }

            public function prepare(string $sha, ?string $branch = null): void
            {
                $this->branch = $branch;
                File::ensureDirectoryExists($this->path.'/config');
                File::ensureDirectoryExists($this->runDir);
                file_put_contents($this->path.'/composer.lock', $this->test->lock);
            }

            public function cleanup(bool $keepBranch = false): void
            {
                File::deleteDirectory($this->path);
                File::deleteDirectory($this->runDir);
            }

            public function environment(): array
            {
                return [];
            }
        });

        $this->app->instance(SuiteRunner::class, new class($test) extends SuiteRunner
        {
            public function __construct(private readonly TestkitInstallerTest $test) {}

            public function runFiles(Workspace $workspace, array $files, string $name, int $timeout, bool $coverage = false): array
            {
                $probe = $workspace->runDir.'/'.$name.'.ndjson';
                file_put_contents($probe, implode("\n", array_map('json_encode', $this->test->runs[$name] ?? [])));

                return ['exit_code' => 0, 'timed_out' => false, 'probe' => $probe, 'output' => 'out'];
            }

            public function php(): string
            {
                return 'php';
            }
        });

        $this->app->instance(Publisher::class, new class($test) extends Publisher
        {
            public function __construct(private readonly TestkitInstallerTest $test) {}

            public function ship(Build $build, Workspace $workspace, array $files, string $title, string $message, string $body): array
            {
                $this->test->shipped = compact('files', 'title', 'message', 'body');

                return ['commit' => str_repeat('e', 40), 'pr_url' => 'https://github.com/x/finance/pull/3', 'pr_number' => 3];
            }
        });

        Process::fake(function (PendingProcess $process) use ($test) {
            $command = implode(' ', (array) $process->command);

            if (str_contains($command, 'testkit:install')) {
                file_put_contents($test->dir.'/config/testkit.php', "<?php\n\nreturn [\n    'routes' => ['public' => ['GET /', 'POST webhooks/stripe']],\n    'database' => [\n        'driver' => 'pgsql',\n    ],\n    'debug_calls' => ['allow' => []],\n    'env_calls' => ['allow' => []],\n    'loading' => ['allow' => ['resources/views/livewire/reports.blade.php@toggle']],\n];\n");
            }

            return match (true) {
                str_contains($command, 'show HEAD:phpunit.xml') => Process::result('<phpunit><php><env name="DB_CONNECTION" value="'.$test->committedDriver.'"/></php></phpunit>'),
                str_contains($command, 'status --porcelain') => Process::result(" M composer.json\n M composer.lock\n?? config/testkit.php\n?? packages/dashcore/testkit/src/Testkit.php\n?? tests/Feature/FleetBaselineTest.php\n M phpunit.xml\n?? .env\n"),
                default => Process::result(),
            };
        });

        $this->runs = [
            'baseline' => [
                ['id' => 'FleetBaselineTest::routes', 'name' => 'every app route has an authentication guard or is declared public', 'status' => 'passed', 'assertions' => 1],
            ],
            'base-suite' => [['id' => 'A::a', 'status' => 'passed', 'assertions' => 1], ['id' => 'A::broken', 'status' => 'failed', 'assertions' => 1]],
            'build-suite' => [['id' => 'A::a', 'status' => 'passed', 'assertions' => 1], ['id' => 'A::broken', 'status' => 'failed', 'assertions' => 1]],
        ];
    }

    private function build(): Build
    {
        return $this->fleetApp->builds()->create(['kind' => BuildKind::Testkit, 'status' => BuildStatus::Queued, 'targets' => [], 'scan_id' => $this->fleetApp->scans()->value('id')]);
    }

    public function test_the_baseline_is_installed_verified_and_proposed_with_its_exceptions_up_for_review(): void
    {
        $build = app(TestkitInstaller::class)->run($this->build());

        $this->assertSame(BuildStatus::Opened, $build->status, (string) $build->error);
        $this->assertSame('tests/testkit-v0.1.3-'.$build->id, $build->branch);
        $this->assertSame(3, $build->pr_number);

        Process::assertRan(fn ($p) => str_ends_with($p->command[0], '/testkit/bin/vendor-into') && $p->command[2] === 'v0.1.3');
        Process::assertRan(fn ($p) => in_array('dashcore/testkit:^0.1', $p->command, true) && in_array('--dev', $p->command, true) && in_array('--no-scripts', $p->command, true));
        Process::assertRan(fn ($p) => array_slice($p->command, 1, 2) === ['artisan', 'package:discover']);

        $this->assertSame(
            ['composer.json', 'composer.lock', 'config/testkit.php', 'packages/dashcore/testkit/src/Testkit.php', 'tests/Feature/FleetBaselineTest.php'],
            $this->shipped['files'],
            'never the workspace\'s own phpunit.xml or .env',
        );
        $this->assertSame('Add the fleet baseline (dashcore/testkit v0.1.3)', $this->shipped['title']);
        $this->assertStringContainsString('- `POST webhooks/stripe`', $this->shipped['body']);
        $this->assertStringContainsString('- `resources/views/livewire/reports.blade.php@toggle`', $this->shipped['body']);
        $this->assertStringContainsString('**Please review them**', $this->shipped['body']);
        $this->assertStringNotContainsString('[!WARNING]', $this->shipped['body']);
    }

    public function test_a_suite_not_on_postgres_gets_the_database_check_switched_off_and_flagged(): void
    {
        $this->committedDriver = 'sqlite';

        $build = app(TestkitInstaller::class)->run($this->build());

        $this->assertSame(BuildStatus::Opened, $build->status, (string) $build->error);
        $this->assertSame(["This suite's phpunit.xml uses sqlite, not Postgres, so the database check is off in config/testkit.php until it moves."], $build->notes);
        $this->assertStringContainsString('[!WARNING]', $this->shipped['body']);
    }

    public function test_an_app_already_on_this_release_is_left_alone(): void
    {
        $this->lock = '{"packages": [], "packages-dev": [{"name": "dashcore/testkit", "version": "0.1.3"}]}';

        $build = app(TestkitInstaller::class)->run($this->build());

        $this->assertSame(BuildStatus::Rejected, $build->status);
        $this->assertSame('Finance already has dashcore/testkit 0.1.3.', $build->error);
        $this->assertNull($this->shipped);
    }

    public function test_a_baseline_that_does_not_pass_is_not_proposed(): void
    {
        $this->runs['baseline'][] = ['id' => 'FleetBaselineTest::migrations', 'name' => 'every migration rolls back and runs again cleanly', 'status' => 'failed', 'message' => 'Tables are left after rolling every migration back', 'assertions' => 1];

        $build = app(TestkitInstaller::class)->run($this->build());

        $this->assertSame(BuildStatus::Rejected, $build->status);
        $this->assertStringContainsString('A baseline check does not pass (failed): every migration rolls back and runs again cleanly — Tables are left', $build->error);
        $this->assertNull($this->shipped);
    }

    public function test_no_testkit_checkout_fails_clearly(): void
    {
        File::deleteDirectory($this->scannerRoot.'/testkit');

        $build = app(TestkitInstaller::class)->run($this->build());

        $this->assertSame(BuildStatus::Failed, $build->status);
        $this->assertStringContainsString('Set SCANNER_TESTKIT_PATH', $build->error);
    }

    public function test_installs_are_requested_once_per_app_and_by_command(): void
    {
        Queue::fake();

        $this->artisan('tests:install-testkit', ['app' => ['finance']])->expectsOutputToContain('queued')->assertSuccessful();
        $this->artisan('tests:install-testkit', ['app' => ['finance']])->expectsOutputToContain('a build is running or a baseline PR is open')->assertSuccessful();

        Queue::assertPushed(RunBuild::class, fn (RunBuild $job) => $job->build->kind === BuildKind::Testkit);
        Queue::assertPushed(RunBuild::class, 1);
        $this->assertNull(app(BuildRequester::class)->requestTestkit(FleetApp::factory()->create()), 'no checkout');
    }

    public function test_a_failure_the_base_already_had_is_not_the_installs(): void
    {
        $build = app(TestkitInstaller::class)->run($this->build());

        $this->assertSame(BuildStatus::Opened, $build->status, (string) $build->error);
    }

    public function test_a_suite_that_will_not_run_on_the_base_commit_fails_the_install(): void
    {
        $this->runs['base-suite'] = [];

        $build = app(TestkitInstaller::class)->run($this->build());

        $this->assertSame(BuildStatus::Failed, $build->status);
        $this->assertStringContainsString('The suite does not run on the base commit', $build->error);
    }
}
