<?php

namespace App\Scanning;

use App\Enums\ScanKind;
use App\Enums\ScanStatus;
use App\Jobs\RunScan;
use App\Models\FleetApp;
use App\Models\Scan;
use App\Services\Install;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * Finds the open pull requests of every fleet app and queues a scan of each
 * new head: one per push, the older one for the same PR set aside. Drafts
 * and PRs aimed anywhere but the default branch are left alone.
 */
class PullRequestScans
{
    private const ATTEMPTS = 3;

    public function __construct(private readonly PullRequestStatus $status) {}

    /**
     * Whether PR scans run here: switched on, and only on Dashcore's own
     * tests. Another fleet runs releases, not branches — nothing to merge.
     */
    public static function allowed(): bool
    {
        return (bool) config('scanner.pull_requests.enabled') && Install::isHome();
    }

    /**
     * @return array<string, array{queued: list<int>, skipped: string|null}>
     */
    public function discover(): array
    {
        if (! Install::isHome()) {
            return [];
        }

        $report = [];

        foreach (FleetApp::query()->orderBy('key')->get() as $app) {
            if (! $app->isScannable() || $app->repo === null) {
                $report[$app->key] = ['queued' => [], 'skipped' => 'no checkout or repository'];

                continue;
            }

            $prs = $this->openPullRequests($app->repo);

            if ($prs === null) {
                $report[$app->key] = ['queued' => [], 'skipped' => 'GitHub could not be read'];

                continue;
            }

            $queued = [];

            foreach ($prs as $pr) {
                if ($this->queue($app, $pr) !== null) {
                    $queued[] = $pr['number'];
                }
            }

            $report[$app->key] = ['queued' => $queued, 'skipped' => null];
        }

        return $report;
    }

    /**
     * @param  array{number: int, headRefOid: string, url: string}  $pr
     */
    public function queue(FleetApp $app, array $pr): ?Scan
    {
        $scan = DB::transaction(function () use ($app, $pr) {
            $scans = $app->pullRequestScans()->where('pr_number', $pr['number'])->where('pr_head_sha', $pr['headRefOid'])->get();
            $failed = $scans->where('status', ScanStatus::Failed)->reject(fn (Scan $s) => $s->error === 'Superseded by a newer push.');

            // A head already judged, or being judged, is done. One whose scan
            // broke is tried again — at most three times, half an hour apart.
            $settled = $scans->contains(fn (Scan $s) => $s->status !== ScanStatus::Failed);
            $retry = ! $settled && $failed->count() > 0 && $failed->count() < self::ATTEMPTS
                && $failed->max('finished_at')?->lt(now()->subMinutes(30));

            if ($settled || ($failed->count() > 0 && ! $retry)) {
                return null;
            }

            // A newer push makes a waiting scan of the old head pointless.
            $app->pullRequestScans()
                ->where('pr_number', $pr['number'])
                ->where('status', ScanStatus::Queued)
                ->update(['status' => ScanStatus::Failed, 'error' => 'Superseded by a newer push.', 'finished_at' => now()]);

            return $app->scans()->create([
                'kind' => ScanKind::PullRequest,
                'status' => ScanStatus::Queued,
                'pr_number' => $pr['number'],
                'pr_url' => $pr['url'],
                'pr_head_sha' => $pr['headRefOid'],
            ]);
        });

        if ($scan !== null) {
            $this->status->publish($scan);
            RunScan::dispatch($scan);
        }

        return $scan;
    }

    /**
     * @return list<array{number: int, headRefOid: string, url: string}>|null
     */
    private function openPullRequests(string $repo): ?array
    {
        $default = Process::timeout(60)->run([(string) config('scanner.gh'), 'repo', 'view', $repo, '--json', 'defaultBranchRef', '-q', '.defaultBranchRef.name']);
        $list = Process::timeout(60)->run([(string) config('scanner.gh'), 'pr', 'list', '--repo', $repo, '--state', 'open', '--limit', '100', '--json', 'number,headRefOid,baseRefName,isDraft,url']);

        if ($default->failed() || $list->failed()) {
            return null;
        }

        $branch = trim($default->output());
        $prs = json_decode($list->output(), true);

        if (! is_array($prs)) {
            return null;
        }

        return array_values(array_map(
            fn (array $pr) => ['number' => (int) $pr['number'], 'headRefOid' => (string) $pr['headRefOid'], 'url' => (string) $pr['url']],
            array_filter($prs, fn (array $pr) => ! ($pr['isDraft'] ?? false) && ($pr['baseRefName'] ?? null) === $branch),
        ));
    }
}
