<?php

namespace Tests\Feature\Scanning;

use App\Enums\ScanStatus;
use App\Jobs\RunScan;
use App\Models\FleetApp;
use App\Models\Scan;
use App\Scanning\PullRequestStatus;
use App\Scanning\Scanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Concerns\UsesFleetFixtures;
use Tests\TestCase;

class PullRequestScansTest extends TestCase
{
    use RefreshDatabase, UsesFleetFixtures;

    private FleetApp $fleetApp;

    /** @var list<array<string, mixed>> */
    private array $openPrs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $root = $this->scannerRootWith(['finance.dashcore.com']);
        $this->fleetApp = FleetApp::factory()->create(['key' => 'finance', 'host' => 'finance.dashcore.com', 'repo' => 'dashcoretech/finance.dashcore.com', 'local_path' => $root.'/finance.dashcore.com']);
        FleetApp::factory()->create(['key' => 'hr', 'repo' => 'dashcoretech/hr.dashcore.com']);

        $this->openPrs = [
            ['number' => 12, 'headRefOid' => str_repeat('1', 40), 'baseRefName' => 'main', 'isDraft' => false, 'url' => 'https://github.com/dashcoretech/finance.dashcore.com/pull/12'],
            ['number' => 13, 'headRefOid' => str_repeat('2', 40), 'baseRefName' => 'main', 'isDraft' => true, 'url' => 'u'],
            ['number' => 14, 'headRefOid' => str_repeat('3', 40), 'baseRefName' => 'release', 'isDraft' => false, 'url' => 'u'],
        ];

        Process::fake(function (PendingProcess $process) {
            $command = implode(' ', (array) $process->command);

            return match (true) {
                str_contains($command, 'gh repo view') => Process::result("main\n"),
                str_contains($command, 'gh pr list') => Process::result(json_encode($this->openPrs)),
                default => Process::result('{}'),
            };
        });

        Queue::fake();
    }

    public function test_each_new_head_of_an_open_pr_against_main_is_queued_and_reported_pending(): void
    {
        $this->artisan('tests:scan-prs')
            ->expectsOutputToContain('queued #12')
            ->expectsOutputToContain('no checkout or repository')
            ->assertSuccessful();

        $scan = $this->fleetApp->pullRequestScans()->sole();
        $this->assertSame(12, $scan->pr_number);
        $this->assertSame(str_repeat('1', 40), $scan->pr_head_sha);
        $this->assertSame(ScanStatus::Queued, $scan->status);

        Queue::assertPushedOn('prs', RunScan::class, fn (RunScan $job) => $job->scan->is($scan));
        Process::assertRan(fn ($p) => in_array('repos/dashcoretech/finance.dashcore.com/statuses/'.str_repeat('1', 40), $p->command, true)
            && in_array('state=pending', $p->command, true)
            && in_array('context=tests/coverage', $p->command, true));
    }

    public function test_a_head_already_scanned_is_not_queued_again_and_a_new_push_supersedes_the_waiting_one(): void
    {
        $this->artisan('tests:scan-prs');
        $this->artisan('tests:scan-prs')->expectsOutputToContain('nothing new');

        $this->openPrs[0]['headRefOid'] = str_repeat('9', 40);
        $this->artisan('tests:scan-prs')->expectsOutputToContain('queued #12');

        $scans = $this->fleetApp->pullRequestScans()->orderBy('id')->get();
        $this->assertCount(2, $scans);
        $this->assertSame(ScanStatus::Failed, $scans[0]->status);
        $this->assertSame('Superseded by a newer push.', $scans[0]->error);
        $this->assertSame(ScanStatus::Queued, $scans[1]->status);
    }

    public function test_a_finished_scan_is_reported_with_its_verdict_and_a_broken_one_as_an_error(): void
    {
        $pass = Scan::factory()->for($this->fleetApp)->create([
            'kind' => 'pull_request', 'pr_number' => 12, 'pr_head_sha' => str_repeat('1', 40), 'status' => ScanStatus::Completed, 'score' => 91.5,
            'verdict' => 'pass', 'verdict_detail' => ['pass' => true, 'new_untested' => [], 'lost' => [], 'newly_tested' => [], 'new_failures' => []],
        ]);
        $fail = Scan::factory()->for($this->fleetApp)->create([
            'kind' => 'pull_request', 'pr_number' => 15, 'pr_head_sha' => str_repeat('5', 40), 'status' => ScanStatus::Completed,
            'verdict' => 'fail', 'verdict_detail' => ['pass' => false, 'new_untested' => ['Routes: GET /x'], 'lost' => [], 'newly_tested' => [], 'new_failures' => []],
        ]);
        $broken = Scan::factory()->for($this->fleetApp)->create(['kind' => 'pull_request', 'pr_number' => 16, 'pr_head_sha' => str_repeat('6', 40), 'status' => ScanStatus::Failed, 'error' => 'composer install failed']);

        $status = app(PullRequestStatus::class);
        $this->assertTrue($status->publish($pass));
        $status->publish($fail);
        $status->publish($broken);

        $posted = fn (string $sha, string $state, string $description) => Process::assertRan(fn ($p) => in_array('repos/dashcoretech/finance.dashcore.com/statuses/'.$sha, $p->command, true)
            && in_array("state={$state}", $p->command, true)
            && in_array("description={$description}", $p->command, true));

        $posted(str_repeat('1', 40), 'success', '91.5% · no new untested code');
        $posted(str_repeat('5', 40), 'failure', '1 new untested');
        $posted(str_repeat('6', 40), 'error', 'The scan could not run: composer install failed');
        $this->assertFalse($status->publish(Scan::factory()->for($this->fleetApp)->create()), 'main scans are not reported');
    }

    public function test_a_scan_waiting_for_its_base_comes_back_in_ten_minutes(): void
    {
        $scan = Scan::factory()->for($this->fleetApp)->create(['kind' => 'pull_request', 'pr_number' => 12, 'pr_head_sha' => str_repeat('1', 40)]);
        $scanner = Mockery::mock(Scanner::class);
        $scanner->shouldReceive('run')->once()->andReturnUsing(fn (Scan $s) => tap($s)->update(['deferrals' => 1]));

        (new RunScan($scan))->handle($scanner, app(PullRequestStatus::class));

        Queue::assertPushed(RunScan::class, fn (RunScan $job) => $job->scan->is($scan) && $job->delay !== null);
        Process::assertRan(fn ($p) => in_array('description=Waiting for main to be scanned first', $p->command, true));
    }

    public function test_conflicts_and_outages_read_as_what_they_are(): void
    {
        $conflict = Scan::factory()->for($this->fleetApp)->create(['kind' => 'pull_request', 'pr_number' => 20, 'pr_head_sha' => str_repeat('7', 40), 'status' => ScanStatus::Failed, 'error' => 'Pull request #20 has no merge ref: it conflicts with its base, or it was closed.']);
        $waiting = Scan::factory()->for($this->fleetApp)->create(['kind' => 'pull_request', 'pr_number' => 21, 'pr_head_sha' => str_repeat('8', 40), 'notes' => ['GitHub could not be reached; trying again shortly.']]);

        app(PullRequestStatus::class)->publish($conflict);
        app(PullRequestStatus::class)->publish($waiting);

        Process::assertRan(fn ($p) => in_array('description=Conflicts with main — resolve them to be scanned', $p->command, true));
        Process::assertRan(fn ($p) => in_array('description=Waiting for GitHub', $p->command, true));
    }

    public function test_a_head_whose_scan_broke_is_tried_again_at_most_three_times_half_an_hour_apart(): void
    {
        $head = ['kind' => 'pull_request', 'pr_number' => 12, 'pr_head_sha' => str_repeat('1', 40), 'status' => ScanStatus::Failed, 'error' => 'ssh: connect to host github.com port 22'];

        Scan::factory()->for($this->fleetApp)->create([...$head, 'finished_at' => now()->subMinutes(5)]);
        $this->artisan('tests:scan-prs')->expectsOutputToContain('nothing new');

        $this->travel(31)->minutes();
        $this->artisan('tests:scan-prs')->expectsOutputToContain('queued #12');

        $this->fleetApp->pullRequestScans()->where('status', ScanStatus::Queued)->update(['status' => ScanStatus::Failed, 'error' => 'again', 'finished_at' => now()->subHour()]);
        Scan::factory()->for($this->fleetApp)->create([...$head, 'finished_at' => now()->subHour()]);
        $this->artisan('tests:scan-prs')->expectsOutputToContain('nothing new');
        $this->assertSame(3, $this->fleetApp->pullRequestScans()->count());
    }
}
