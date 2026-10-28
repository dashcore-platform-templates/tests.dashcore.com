<?php

namespace Tests\Feature\Scanning;

use App\Building\BuildRequester;
use App\Building\Publisher;
use App\Models\Build;
use App\Models\FleetApp;
use App\Models\Surface;
use App\Scanning\HostRuntime;
use App\Scanning\PullRequestScans;
use App\Scanning\Workspace;
use App\Services\FleetExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\Concerns\UsesFleetFixtures;
use Tests\TestCase;

/**
 * A tests install for a fleet other than Dashcore's scans what that fleet's
 * people use — each app at the release it runs live — and never judges a
 * pull request or builds tests, so it never pushes anything to Templates.
 */
class OtherFleetInstallTest extends TestCase
{
    use RefreshDatabase, UsesFleetFixtures;

    private string $repo;

    private FleetApp $fleetApp;

    protected function setUp(): void
    {
        parent::setUp();

        $root = $this->scannerRootWith(['sales.acme.practice'], git: false);
        $this->repo = $root.'/sales.acme.practice';
        config(['scanner.git_base' => $root, 'scanner.workspace' => $root.'/_workspace']);

        $this->git('init', '--quiet', '--initial-branch=main');
        $this->commit('v1.4.0 release');
        $this->git('tag', 'v1.4.0');
        $this->commit('Unreleased work on main');

        $this->fleetApp = FleetApp::factory()->create(['key' => 'sales', 'host' => 'sales.acme.practice.dashcore.com', 'repo' => 'origin', 'local_path' => $this->repo]);

        config(['install.fleet' => 'acme', 'install.host' => 'tests.acme.practice.dashcore.com']);
    }

    private function git(string ...$args): string
    {
        return Process::path($this->repo)->run(['git', ...$args])->throw()->output();
    }

    private function commit(string $message): void
    {
        file_put_contents($this->repo.'/CHANGES', $message."\n", FILE_APPEND);
        $this->git('add', '.');
        $this->git('-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '--quiet', '-m', $message);
    }

    /** @param  array{release: ?string, commit: ?string, local: ?string}|null  $live */
    private function fleetsSays(?array $live): void
    {
        $export = Mockery::mock(FleetExport::class);
        $export->shouldReceive('live')->with('acme', 'sales')->andReturn($live);
        $this->app->instance(FleetExport::class, $export);
    }

    public function test_an_app_is_scanned_at_the_release_its_fleet_runs_not_the_default_branch(): void
    {
        $this->fleetsSays(['release' => 'v1.4.0', 'commit' => null, 'local' => 'sales.acme.practice']);

        $revision = (new Workspace($this->fleetApp, $this->fleetApp->scans()->create(['status' => 'queued'])))->resolveRevision();

        $this->assertSame('v1.4.0', $revision['ref']);
        $this->assertSame(trim($this->git('rev-parse', 'v1.4.0^{commit}')), $revision['sha']);
        $this->assertSame('v1.4.0 release', $revision['subject']);
        $this->assertNotSame(trim($this->git('rev-parse', 'main')), $revision['sha']);
    }

    public function test_the_running_commit_wins_over_the_pinned_release(): void
    {
        $running = trim($this->git('rev-parse', 'v1.4.0^{commit}'));
        $this->fleetsSays(['release' => 'v9.9.9', 'commit' => $running, 'local' => null]);

        $revision = (new Workspace($this->fleetApp, $this->fleetApp->scans()->create(['status' => 'queued'])))->resolveRevision();

        $this->assertSame($running, $revision['sha']);
    }

    public function test_an_app_whose_release_fleets_does_not_know_is_not_scanned_at_main_instead(): void
    {
        $this->fleetsSays(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fleets does not say which release sales runs in the acme fleet');

        (new Workspace($this->fleetApp, $this->fleetApp->scans()->create(['status' => 'queued'])))->resolveRevision();
    }

    public function test_the_home_install_still_scans_the_default_branch(): void
    {
        config(['install.fleet' => 'dashcore']);
        $this->fleetsSays(null);

        $revision = (new Workspace($this->fleetApp, $this->fleetApp->scans()->create(['status' => 'queued'])))->resolveRevision();

        $this->assertSame(trim($this->git('rev-parse', 'main')), $revision['sha']);
    }

    public function test_pull_requests_are_never_scanned(): void
    {
        Process::fake();
        Queue::fake();
        config(['scanner.pull_requests.enabled' => true]);

        $this->assertFalse(PullRequestScans::allowed());
        $this->assertSame([], app(PullRequestScans::class)->discover());
        $this->artisan('tests:scan-prs')->assertSuccessful();
        $this->assertFalse(app(HostRuntime::class)->describe()['schedule']['pull_request_scans']);

        Process::assertNothingRan();
        Queue::assertNothingPushed();
    }

    public function test_tests_are_never_built_and_nothing_is_pushed(): void
    {
        Process::fake();
        Queue::fake();
        config(['scanner.build.nightly' => true, 'scanner.build.publish' => true]);
        Surface::factory()->for($this->fleetApp)->create();

        $this->assertNull(app(BuildRequester::class)->request($this->fleetApp));
        $this->assertNull(app(BuildRequester::class)->requestTestkit($this->fleetApp));
        $this->artisan('tests:build')->assertFailed();
        $this->artisan('tests:install-testkit')->assertFailed();
        $this->assertFalse(app(HostRuntime::class)->describe()['schedule']['test_builds']);

        $build = Build::factory()->for($this->fleetApp)->create();
        $workspace = Mockery::mock(Workspace::class);
        $workspace->path = $this->repo;

        try {
            app(Publisher::class)->ship($build, $workspace, ['tests/ExampleTest.php'], 'Tests', 'Tests', 'Tests');
            $this->fail('a build was shipped from another fleet\'s tests');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('nothing is committed or pushed for the acme fleet', $e->getMessage());
        }

        Process::assertNothingRan();
        Queue::assertNothingPushed();
    }

    public function test_fleets_export_is_read_for_the_release_each_app_runs(): void
    {
        $checkout = $this->scannerRootWith(['fleets.dashcore.com']).'/fleets.dashcore.com';
        config(['install.fleets_checkout' => $checkout]);
        Process::fake(['*fleet:export*' => Process::result(json_encode(['fleets' => [
            ['slug' => 'acme', 'apps' => [
                ['key' => 'sales', 'local' => 'sales.acme.practice', 'release' => 'v1.3.0', 'running_release' => 'v1.4.0', 'running_commit' => 'abc1234'],
                ['key' => 'hr', 'local' => 'hr.acme.practice', 'release' => 'v2.0.0'],
                ['key' => 'ceo', 'local' => 'ceo.acme.practice', 'running_commit' => 'not a sha'],
            ]],
        ]]))]);

        $export = app(FleetExport::class);

        $this->assertSame(['release' => 'v1.4.0', 'commit' => 'abc1234', 'local' => 'sales.acme.practice'], $export->live('acme', 'sales'));
        $this->assertSame(['release' => 'v2.0.0', 'commit' => null, 'local' => 'hr.acme.practice'], $export->live('acme', 'hr'));
        $this->assertSame(['release' => null, 'commit' => null, 'local' => 'ceo.acme.practice'], $export->live('acme', 'ceo'));
        $this->assertNull($export->live('acme', 'finance'));
        $this->assertNull($export->live('globex', 'sales'));
        Process::assertRanTimes(fn ($process) => str_contains(implode(' ', (array) $process->command), 'fleet:export'), 1);
    }
}
