<?php

namespace App\Scanning;

use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Whether the queue worker that started a scan is still alive: a process
 * on this Mac with that pid that is still an artisan process (a queue
 * worker, or `tests:scan --now`). A pid handed to something else since is
 * not the worker.
 */
class WorkerProcess
{
    public function alive(?string $host, ?int $pid): bool
    {
        if ($pid === null || $pid < 1) {
            return false;
        }

        // Another machine's worker cannot be asked; its heartbeat is the only word.
        if ($host !== null && $host !== gethostname()) {
            return false;
        }

        try {
            $result = Process::timeout(10)->run(['ps', '-p', (string) $pid, '-o', 'command=']);
        } catch (Throwable) {
            return false;
        }

        return $result->successful() && str_contains($result->output(), 'artisan');
    }
}
