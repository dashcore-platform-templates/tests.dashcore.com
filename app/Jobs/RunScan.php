<?php

namespace App\Jobs;

use App\Enums\ScanStatus;
use App\Models\Scan;
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
        $this->onQueue(config('scanner.queue.queue'));
    }

    public function handle(Scanner $scanner): void
    {
        if ($this->scan->status !== ScanStatus::Queued) {
            return;
        }

        $scanner->run($this->scan);
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
        }
    }
}
