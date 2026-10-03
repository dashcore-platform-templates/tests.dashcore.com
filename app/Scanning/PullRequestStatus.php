<?php

namespace App\Scanning;

use App\Enums\ScanStatus;
use App\Models\Scan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Reports a pull request scan to GitHub as a commit status on the PR's head,
 * which branch protection can require. Pending while it waits or runs, success
 * or failure once judged, error when the scan itself broke — an error is the
 * scanner's problem, and says so, rather than a verdict on the PR.
 */
class PullRequestStatus
{
    public function __construct(private readonly PullRequestVerdict $verdicts) {}

    public function publish(Scan $scan): bool
    {
        $repo = $scan->fleetApp?->repo;

        if (! $scan->isPullRequest() || $repo === null || $scan->pr_head_sha === null) {
            return false;
        }

        [$state, $description] = match (true) {
            $scan->status === ScanStatus::Completed => [
                ($scan->verdict === 'pass') ? 'success' : 'failure',
                $this->verdicts->describe($scan->verdict_detail ?? ['pass' => false, 'new_untested' => [], 'lost' => [], 'newly_tested' => [], 'new_failures' => []], $scan->score === null ? null : (float) $scan->score),
            ],
            $scan->status === ScanStatus::Failed && str_contains((string) $scan->error, 'has no merge ref') => ['error', 'Conflicts with main — resolve them to be scanned'],
            $scan->status === ScanStatus::Failed => ['error', 'The scan could not run: '.Str::limit((string) $scan->error, 100, '…')],
            $scan->status === ScanStatus::Queued && str_starts_with((string) ($scan->notes[0] ?? ''), 'GitHub could not be reached') => ['pending', 'Waiting for GitHub'],
            $scan->status === ScanStatus::Queued && $scan->deferrals > 0 => ['pending', 'Waiting for main to be scanned first'],
            $scan->status === ScanStatus::Queued => ['pending', 'Queued'],
            default => ['pending', 'Running the suite with coverage'],
        };

        $result = Process::timeout(60)->run([
            (string) config('scanner.gh'), 'api', '--method', 'POST', "repos/{$repo}/statuses/{$scan->pr_head_sha}",
            '-f', "state={$state}",
            '-f', 'context='.config('scanner.pull_requests.context'),
            '-f', 'description='.mb_substr($description, 0, 140),
            '-f', 'target_url='.route('scans.show', $scan),
        ]);

        if ($result->failed()) {
            report(new \RuntimeException("Could not post the {$state} status for {$repo}@{$scan->pr_head_sha}: ".trim($result->errorOutput())));
        }

        return $result->successful();
    }
}
