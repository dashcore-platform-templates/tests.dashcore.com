<?php

namespace Tests\Feature\Building;

use App\Building\AgentRunner;
use App\Building\Builder;
use App\Building\BuildRequester;
use App\Building\BuildVerifier;
use App\Building\Publisher;
use App\Building\TestkitInstaller;
use App\Enums\BuildStatus;
use App\Enums\SurfaceStatus;
use App\Jobs\RunBuild;
use App\Models\Build;
use App\Models\FleetApp;
use App\Models\Scan;
use App\Scanning\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\UsesFleetFixtures;
use Tests\TestCase;

class BuilderTest extends TestCase
{
    use RefreshDatabase, UsesFleetFixtures;

    private FleetApp $fleetApp;

    public array $agent = ['ok' => true, 'report' => "Done.\n```json\n{\"tests\": [{\"file\": \"tests/Feature/PricingTest.php\", \"covers\": [\"1\"]}], \"suspected_bugs\": [{\"target\": \"2\", \"description\": \"rounds the wrong way\"}], \"skipped\": []}\n```", 'cost' => 0.42, 'output' => '{}'];

    public array $verification = [];

    public ?\Throwable $publishFails = null;

    public ?string $prompt = null;

    public ?bool $keptBranch = null;

    public ?string $branch = null;

    protected function setUp(): void
    {
        parent::setUp();

        $root = $this->scannerRootWith(['finance.dashcore.com']);
        $this->fleetApp = FleetApp::factory()->create(['key' => 'finance', 'name' => 'Finance', 'host' => 'finance.dashcore.com', 'local_path' => $root.'/finance.dashcore.com']);
        Scan::factory()->completed()->for($this->fleetApp)->create();

        $test = $this;

        $this->app->bind(Workspace::class, fn ($app, array $params) => new class($params['app'], $params['for'], $test) extends Workspace
        {
            public function __construct(FleetApp $app, Scan|Build $for, private readonly BuilderTest $test)
            {
                parent::__construct($app, $for);
            }

            public function resolveRevision(): array
            {
                return ['ref' => 'origin/main', 'sha' => str_repeat('b', 40), 'subject' => 'x'];
            }

            public function prepare(string $sha, ?string $branch = null): void
            {
                $this->branch = $branch;
                $this->test->branch = $branch;
            }

            public function cleanup(bool $keepBranch = false): void
            {
                $this->test->keptBranch = $keepBranch;
            }
        });

        $this->app->instance(AgentRunner::class, new class($test) extends AgentRunner
        {
            public function __construct(private readonly BuilderTest $test) {}

            public function run(Workspace $workspace, string $prompt): array
            {
                $this->test->prompt = $prompt;

                return $this->test->agent;
            }
        });

        $this->app->instance(BuildVerifier::class, new class($test) extends BuildVerifier
        {
            public function __construct(private readonly BuilderTest $test) {}

            public function verify(Build $build, Workspace $workspace, Collection $targets): array
            {
                return $this->test->verification;
            }
        });

        $this->app->instance(Publisher::class, new class($test) extends Publisher
        {
            public function __construct(private readonly BuilderTest $test) {}

            public function publish(Build $build, Workspace $workspace, array $files, array $verification): array
            {
                if ($this->test->publishFails) {
                    throw $this->test->publishFails;
                }

                return config('scanner.build.publish')
                    ? ['commit' => str_repeat('c', 40), 'pr_url' => 'https://github.com/dashcoretech/finance.dashcore.com/pull/7', 'pr_number' => 7]
                    : ['commit' => str_repeat('c', 40), 'pr_url' => null, 'pr_number' => null];
            }
        });
    }

    private function build(): Build
    {
        $total = $this->fleetApp->surfaces()->create(['kind' => 'service', 'identifier' => 'App\Services\Pricing::total', 'label' => 'Pricing::total', 'status' => SurfaceStatus::Missing, 'file' => 'app/Services/Pricing.php', 'start_line' => 10, 'end_line' => 12]);
        $round = $this->fleetApp->surfaces()->create(['kind' => 'route', 'identifier' => 'GET reports', 'label' => 'GET /reports', 'status' => SurfaceStatus::Missing, 'meta' => ['middleware' => ['web', 'auth'], 'name' => 'reports']]);

        $this->verification = [
            'accepted' => true, 'reasons' => [], 'files' => ['tests/Feature/PricingTest.php'], 'reverted' => ['app/Services/Pricing.php'], 'tests' => 4,
            'targets' => [
                $total->id => ['label' => 'Pricing::total', 'status' => 'tested', 'mutation' => 'killed', 'proven' => true, 'tests' => ['totals']],
                $round->id => ['label' => 'GET /reports', 'status' => 'missing', 'mutation' => null, 'proven' => false, 'tests' => []],
            ],
        ];

        return $this->fleetApp->builds()->create(['status' => BuildStatus::Queued, 'targets' => [$total->id, $round->id], 'scan_id' => $this->fleetApp->scans()->value('id')]);
    }

    public function test_verified_tests_become_a_pr_for_review(): void
    {
        $build = app(Builder::class)->run($this->build());

        $this->assertSame(BuildStatus::Opened, $build->status, (string) $build->error);
        $this->assertSame(7, $build->pr_number);
        $this->assertSame(1, $build->targets_proven);
        $this->assertSame(4, $build->tests_added);
        $this->assertSame('0.4200', $build->cost_usd);
        $this->assertSame(str_repeat('b', 40), $build->base_sha);
        $this->assertStringStartsWith('tests/auto-'.$build->id.'-pricingtotal', $build->branch);
        $this->assertSame($build->branch, $this->branch);
        $this->assertSame([['target' => '2', 'description' => 'rounds the wrong way']], $build->agent_report['suspected_bugs']);
        $this->assertSame(['Put back a change outside tests/ and database/factories/: app/Services/Pricing.php'], $build->notes);
        $this->assertFalse($this->keptBranch, 'the remote holds the branch once pushed');
    }

    public function test_the_agent_is_briefed_on_each_target_and_the_rules_it_will_be_held_to(): void
    {
        app(Builder::class)->run($this->build());

        $this->assertStringContainsString('Pricing::total — app/Services/Pricing.php:10-12 — no test reaches it', $this->prompt);
        $this->assertStringContainsString('GET /reports — named reports — middleware: web, auth', $this->prompt);
        $this->assertStringContainsString('Only create or edit files under tests/', $this->prompt);
        $this->assertStringContainsString('mutation-checked', $this->prompt);
        $this->assertStringContainsString('Its tests use PHPUnit classes', $this->prompt, 'no vendor/bin/pest in this workspace');
    }

    public function test_with_publishing_off_the_branch_is_kept_locally(): void
    {
        config(['scanner.build.publish' => false]);

        $build = app(Builder::class)->run($this->build());

        $this->assertSame(BuildStatus::Ready, $build->status);
        $this->assertNull($build->pr_url);
        $this->assertTrue($this->keptBranch);
    }

    public function test_tests_that_fail_verification_are_never_proposed(): void
    {
        $build = $this->build();
        $this->verification['accepted'] = false;
        $this->verification['reasons'] = ['A new test does not pass (failed): totals', 'None of the targets is proven by the new tests.'];
        $this->publishFails = new RuntimeException('must not publish');

        $build = app(Builder::class)->run($build);

        $this->assertSame(BuildStatus::Rejected, $build->status);
        $this->assertSame("A new test does not pass (failed): totals\nNone of the targets is proven by the new tests.", $build->error);
        $this->assertNull($build->pr_url);
        $this->assertFalse($this->keptBranch);
    }

    public function test_an_agent_that_does_not_finish_fails_the_build(): void
    {
        $this->agent = ['ok' => false, 'report' => null, 'cost' => null, 'output' => 'The agent ran past the build timeout.'];

        $build = app(Builder::class)->run($this->build());

        $this->assertSame(BuildStatus::Failed, $build->status);
        $this->assertStringContainsString('The agent did not finish: The agent ran past the build timeout.', $build->error);
    }

    public function test_a_failed_push_or_pr_fails_the_build_and_says_why(): void
    {
        $this->publishFails = new RuntimeException('git push failed: permission denied');

        $build = app(Builder::class)->run($this->build());

        $this->assertSame(BuildStatus::Failed, $build->status);
        $this->assertSame('git push failed: permission denied', $build->error);
    }

    public function test_the_agents_closing_json_is_read_when_present(): void
    {
        $builder = app(Builder::class);

        $this->assertNull($builder->report('All done, no JSON.'));
        $this->assertNull($builder->report("```json\nnot json\n```"));
        $this->assertSame(['skipped' => [['target' => '3', 'reason' => 'needs a browser']]], $builder->report("```json\n{\"x\":1}\n```\nthen\n```json\n{\"skipped\": [{\"target\": \"3\", \"reason\": \"needs a browser\"}], \"extra\": true}\n```"));
    }

    public function test_builds_are_queued_one_at_a_time_per_app(): void
    {
        Queue::fake();
        $this->fleetApp->surfaces()->create(['kind' => 'service', 'identifier' => 'A::a', 'label' => 'A::a', 'status' => SurfaceStatus::Missing, 'file' => 'app/A.php']);

        $build = app(BuildRequester::class)->request($this->fleetApp);

        Queue::assertPushedOn('builds', RunBuild::class, fn (RunBuild $job) => $job->build->is($build) && $job->connection === 'scans');
        $this->assertGreaterThan((new RunBuild($build))->timeout, config('queue.connections.scans.retry_after'), 'a running build must never be handed to a second worker');

        $this->fleetApp->surfaces()->create(['kind' => 'service', 'identifier' => 'B::b', 'label' => 'B::b', 'status' => SurfaceStatus::Missing, 'file' => 'app/B.php']);
        $this->assertNull(app(BuildRequester::class)->request($this->fleetApp), 'one is already queued');
        Queue::assertPushed(RunBuild::class, 1);
    }

    public function test_the_job_runs_only_queued_builds_and_a_dead_worker_fails_the_build(): void
    {
        $done = $this->fleetApp->builds()->create(['status' => BuildStatus::Opened, 'targets' => []]);
        $builder = \Mockery::mock(Builder::class);
        $builder->shouldNotReceive('run');
        $installer = \Mockery::mock(TestkitInstaller::class);
        $installer->shouldNotReceive('run');
        (new RunBuild($done))->handle($builder, $installer);

        $testkit = $this->fleetApp->builds()->create(['kind' => 'testkit', 'status' => BuildStatus::Queued, 'targets' => []]);
        $installer = \Mockery::mock(TestkitInstaller::class);
        $installer->shouldReceive('run')->once();
        (new RunBuild($testkit))->handle($builder, $installer);

        $writing = $this->fleetApp->builds()->create(['status' => BuildStatus::Writing, 'targets' => []]);
        (new RunBuild($writing))->failed(new RuntimeException('Killed'));
        $this->assertSame(BuildStatus::Failed, $writing->fresh()->status);
    }
}
