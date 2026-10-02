<?php

namespace Tests\Feature\Building;

use App\Building\Publisher;
use App\Enums\BuildStatus;
use App\Models\Build;
use App\Models\FleetApp;
use App\Scanning\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

class PublisherTest extends TestCase
{
    use RefreshDatabase;

    private Build $build;

    private Workspace $workspace;

    private array $verification;

    protected function setUp(): void
    {
        parent::setUp();

        $app = FleetApp::factory()->create(['name' => 'Finance', 'repo' => 'dashcoretech/finance.dashcore.com']);
        $this->build = $app->builds()->create([
            'status' => BuildStatus::Publishing,
            'targets' => [1, 2],
            'base_ref' => 'origin/main',
            'base_sha' => str_repeat('a', 40),
            'branch' => 'tests/auto-9-pricing',
            'agent_report' => ['suspected_bugs' => [['target' => '2', 'description' => 'rounds the wrong way']], 'skipped' => []],
        ]);

        $this->workspace = new Workspace($app, $this->build);
        $this->workspace->branch = 'tests/auto-9-pricing';
        $this->workspace->runDir = realpath(sys_get_temp_dir()).'/publish-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace->runDir);

        $this->verification = [
            'files' => ['tests/Feature/PricingTest.php'],
            'targets' => [
                1 => ['label' => 'Pricing::total', 'status' => 'tested', 'mutation' => 'killed', 'proven' => true, 'tests' => ['totals', 'totals with tax']],
                2 => ['label' => 'Pricing::round', 'status' => 'missing', 'mutation' => null, 'proven' => false, 'tests' => []],
            ],
        ];
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace->runDir);

        parent::tearDown();
    }

    private function fake(array $failures = []): void
    {
        Process::fake(function (PendingProcess $process) use ($failures) {
            $command = implode(' ', (array) $process->command);

            foreach ($failures as $needle => $error) {
                if (str_contains($command, $needle)) {
                    return Process::result(errorOutput: $error, exitCode: 1);
                }
            }

            return match (true) {
                str_contains($command, 'rev-parse') => Process::result(str_repeat('c', 40)),
                str_contains($command, 'pr create') => Process::result("Creating pull request\nhttps://github.com/dashcoretech/finance.dashcore.com/pull/12\n"),
                default => Process::result(),
            };
        });
    }

    public function test_only_the_verified_files_are_committed_pushed_and_proposed(): void
    {
        $this->fake();

        $result = app(Publisher::class)->publish($this->build, $this->workspace, $this->verification['files'], $this->verification);

        $this->assertSame(['commit' => str_repeat('c', 40), 'pr_url' => 'https://github.com/dashcoretech/finance.dashcore.com/pull/12', 'pr_number' => 12], $result);

        Process::assertRan(fn ($p) => $p->command === ['git', 'add', '--', 'tests/Feature/PricingTest.php']);
        Process::assertRan(fn ($p) => $p->command[1] === 'commit'
            && str_starts_with($p->command[4], "Add tests for Pricing::total\n")
            && str_contains($p->command[4], 'Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>'));
        Process::assertRan(fn ($p) => $p->command === ['git', 'push', '--set-upstream', 'origin', 'tests/auto-9-pricing']);
        Process::assertRan(fn ($p) => array_slice($p->command, 0, 3) === ['gh', 'pr', 'create']
            && in_array('dashcoretech/finance.dashcore.com', $p->command, true)
            && $p->command[array_search('--base', $p->command, true) + 1] === 'main'
            && $p->command[array_search('--head', $p->command, true) + 1] === 'tests/auto-9-pricing');

        $body = file_get_contents($this->workspace->runDir.'/pr-body.md');
        $this->assertStringContainsString('| Pricing::total | ✅ proven | caught | totals; totals with tax |', $body);
        $this->assertStringContainsString('| Pricing::round | ❌ not proven | n/a |  |', $body);
        $this->assertStringContainsString('- 2: rounds the wrong way', $body);
        $this->assertStringContainsString('- `tests/Feature/PricingTest.php`', $body);
        $this->assertStringContainsString('🤖 Generated with [Claude Code](https://claude.com/claude-code)', $body);
    }

    public function test_with_publishing_off_it_only_commits(): void
    {
        config(['scanner.build.publish' => false]);
        $this->fake();

        $result = app(Publisher::class)->publish($this->build, $this->workspace, $this->verification['files'], $this->verification);

        $this->assertNull($result['pr_url']);
        Process::assertDidntRun(fn ($p) => in_array('push', (array) $p->command, true));
        Process::assertDidntRun(fn ($p) => ($p->command[0] ?? null) === 'gh');
    }

    public function test_a_failed_push_stops_before_a_pr(): void
    {
        $this->fake(['push' => 'permission denied']);

        try {
            app(Publisher::class)->publish($this->build, $this->workspace, $this->verification['files'], $this->verification);
            $this->fail('expected an exception');
        } catch (RuntimeException $e) {
            $this->assertSame('git push failed: permission denied', $e->getMessage());
        }

        Process::assertDidntRun(fn ($p) => ($p->command[0] ?? null) === 'gh');
    }

    public function test_pr_states_are_followed_until_merged_or_closed(): void
    {
        $merged = $this->build->fleetApp->builds()->create(['status' => BuildStatus::Opened, 'targets' => [], 'pr_url' => 'https://github.com/x/y/pull/1']);
        $closed = $this->build->fleetApp->builds()->create(['status' => BuildStatus::Opened, 'targets' => [], 'pr_url' => 'https://github.com/x/y/pull/2']);
        $waiting = $this->build->fleetApp->builds()->create(['status' => BuildStatus::Opened, 'targets' => [], 'pr_url' => 'https://github.com/x/y/pull/3']);

        Process::fake([
            '*pull/1*' => Process::result('{"state":"MERGED"}'),
            '*pull/2*' => Process::result('{"state":"CLOSED"}'),
            '*pull/3*' => Process::result('{"state":"OPEN"}'),
        ]);

        $this->artisan('tests:sync-builds')->assertSuccessful();

        $this->assertSame(BuildStatus::Merged, $merged->fresh()->status);
        $this->assertSame(BuildStatus::Closed, $closed->fresh()->status);
        $this->assertSame(BuildStatus::Opened, $waiting->fresh()->status);
    }

    public function test_a_local_build_whose_branch_was_deleted_frees_its_targets(): void
    {
        $repo = realpath(sys_get_temp_dir());
        $this->build->fleetApp->update(['local_path' => $repo]);
        $gone = $this->build->fleetApp->builds()->create(['status' => BuildStatus::Ready, 'targets' => [5], 'branch' => 'tests/auto-1-gone']);
        $kept = $this->build->fleetApp->builds()->create(['status' => BuildStatus::Ready, 'targets' => [6], 'branch' => 'tests/auto-2-kept']);

        Process::fake([
            '*refs/heads/tests/auto-1-gone*' => Process::result(exitCode: 1),
            '*refs/heads/tests/auto-2-kept*' => Process::result(str_repeat('a', 40)),
        ]);

        $this->artisan('tests:sync-builds')->expectsOutputToContain('branch gone')->assertSuccessful();

        $this->assertSame(BuildStatus::Closed, $gone->fresh()->status);
        $this->assertStringContainsString('is gone', $gone->fresh()->notes[0]);
        $this->assertSame(BuildStatus::Ready, $kept->fresh()->status);
    }
}
