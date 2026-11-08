<?php

namespace App\Scanning;

use App\Models\Scan;
use Throwable;

/**
 * The pulse of the scan this worker is running. The scanner stamps the scan
 * when it starts, and the suite's and the workspace's output keep stamping
 * it (at most once a minute) while they run, beside the worker's pid and
 * host. `tests:reap-scans` fails a scan whose pulse has stopped and whose
 * worker is gone — a worker launchd killed mid-scan once left ten scans
 * "running" for fourteen hours, holding every app's next scan back (REQ-683).
 *
 * Never allowed to break the scan it watches.
 */
class ScanHeartbeat
{
    private const EVERY_SECONDS = 60;

    private ?int $scanId = null;

    private int $lastBeat = 0;

    public function start(Scan $scan): void
    {
        $this->scanId = $scan->id;
        $this->stamp([
            'worker_pid' => getmypid() ?: null,
            'worker_host' => gethostname() ?: null,
        ]);
    }

    /** Stamp the running scan, at most once a minute. */
    public function beat(): void
    {
        if ($this->scanId === null || time() - $this->lastBeat < self::EVERY_SECONDS) {
            return;
        }

        $this->stamp();
    }

    public function stop(): void
    {
        $this->scanId = null;
    }

    /** @param  array<string, mixed>  $also */
    private function stamp(array $also = []): void
    {
        $this->lastBeat = time();

        try {
            Scan::query()->whereKey($this->scanId)->update(['heartbeat_at' => now(), ...$also]);
        } catch (Throwable) {
            // A missed beat is only a later one; the scan goes on.
        }
    }
}
