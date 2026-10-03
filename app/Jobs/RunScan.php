<?php

namespace App\Jobs;

use App\Enums\ScanStatus;
use App\Models\Scan;
use App\Scanning\PullRequestStatus;
use App\Scanning\Scanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one scan on the long-running `scans` queue connection. One attempt
 * only: a scan that dies half way leaves a worktree and a database that the
 * next attempt would have to reason about, so it is marked failed instead and
 * the next scheduled scan starts clean.
 */
class RunScan implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public Scan $scan)
    {
        $this->timeout = (int) config('scanner.timeout') + 1200;
        $this->onConnection(config('scanner.queue.connection'));
        $this->onQueue($scan->isPullRequest() ? config('scanner.pull_requests.queue') : config('scanner.queue.queue'));
    }

    public function handle(Scanner $scanner, PullRequestStatus $status): void
    {
        if ($this->scan->status !== ScanStatus::Queued) {
            return;
        }

        $scan = $scanner->run($this->scan);

        if (! $scan->isPullRequest()) {
            return;
        }

        $status->publish($scan);

        // Still queued means it is waiting: for its base to be scanned, or for GitHub.
        if ($scan->status === ScanStatus::Queued) {
            self::dispatch($scan)->delay(now()->addMinutes(10));
        }
    }

    public function failed(?Throwable $e): void
    {
        $this->scan->refresh();

        if ($this->scan->status->isActive()) {
            $this->scan->update([
                'status' => ScanStatus::Failed,
                'error' => 'The scan worker stopped before the scan finished'.($e ? ': '.$e->getMessage() : '.'),
                'finished_at' => now(),
            ]);

            app(PullRequestStatus::class)->publish($this->scan);
        }
    }
}
