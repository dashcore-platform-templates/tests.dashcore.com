<?php

namespace App\Scanning;

use App\Enums\ScanKind;
use App\Enums\ScanStatus;
use App\Jobs\RunScan;
use App\Models\FleetApp;
use App\Models\Scan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Catches scans whose worker died. A scan stamps its heartbeat while it
 * runs (ScanHeartbeat); one silent for `scanner.reap_after_minutes` whose
 * worker process is gone is marked failed with why, its reserved job is
 * deleted so no second worker picks it up hours later, and one fresh scan
 * of the app is queued — once per app per day, so a scan that kills its
 * worker every time does not loop. A queued scan whose job has vanished
 * from the queue is failed and requeued the same way. Before this, a dead
 * worker's scans stayed "running" for the whole retry_after (14 hours)
 * and every app read "already scanning" (REQ-683).
 */
class ScanReaper
{
    public const ERROR_PREFIX = 'The scan worker stopped before the scan finished';

    public function __construct(
        private readonly WorkerProcess $workers,
        private readonly ScanRequester $requester,
        private readonly PullRequestStatus $status,
    ) {}

    /**
     * @return list<array{scan: Scan, why: string, requeued: ?Scan}>
     */
    public function reap(): array
    {
        $silentSince = now()->subMinutes(max(1, (int) config('scanner.reap_after_minutes', 15)));
        $reaped = [];

        foreach ($this->silentRunning($silentSince) as $scan) {
            if ($this->workers->alive($scan->worker_host, $scan->worker_pid)) {
                continue;
            }

            $last = $scan->heartbeat_at ?? $scan->started_at ?? $scan->updated_at;
            $why = self::ERROR_PREFIX.': no heartbeat since '.$this->when($last)
                .($scan->worker_pid ? " and its worker (pid {$scan->worker_pid}) is gone" : ' and no worker is running it')
                .' — reaped by tests:reap-scans.';

            $reaped[] = $this->fail($scan, $why);
        }

        $queuedJobs = $this->queuedScanIds();

        foreach ($this->staleQueued($silentSince) as $scan) {
            if ($queuedJobs === null || in_array($scan->id, $queuedJobs, true)) {
                continue;
            }

            $reaped[] = $this->fail($scan, 'The scan\'s queued job is gone from the queue, so no worker would ever run it — reaped by tests:reap-scans.');
        }

        return $reaped;
    }

    /** @return iterable<Scan> */
    private function silentRunning(Carbon $silentSince): iterable
    {
        return Scan::query()
            ->whereIn('status', [ScanStatus::Preparing, ScanStatus::Running, ScanStatus::Analysing, ScanStatus::Mutating])
            ->whereHas('fleetApp', fn ($q) => $q->real())
            ->whereRaw('coalesce(heartbeat_at, started_at, updated_at) < ?', [$silentSince])
            ->get();
    }

    /** @return iterable<Scan> */
    private function staleQueued(Carbon $silentSince): iterable
    {
        return Scan::query()
            ->where('status', ScanStatus::Queued)
            ->whereHas('fleetApp', fn ($q) => $q->real())
            ->where('updated_at', '<', $silentSince)
            ->get();
    }

    /**
     * @return array{scan: Scan, why: string, requeued: ?Scan}
     */
    private function fail(Scan $scan, string $why): array
    {
        DB::transaction(function () use ($scan, $why) {
            $scan->update([
                'status' => ScanStatus::Failed,
                'error' => $why,
                'finished_at' => now(),
            ]);

            $this->deleteJobsOf($scan->id);
        });

        if ($scan->isPullRequest()) {
            try {
                $this->status->publish($scan);
            } catch (Throwable $e) {
                report($e);
            }

            return ['scan' => $scan, 'why' => $why, 'requeued' => null];
        }

        return ['scan' => $scan, 'why' => $why, 'requeued' => $this->requeue($scan)];
    }

    /** One fresh scan of the app, once per app per day. */
    private function requeue(Scan $scan): ?Scan
    {
        $app = FleetApp::query()->real()->find($scan->fleet_app_id);

        if ($app === null || $scan->kind !== ScanKind::Main) {
            return null;
        }

        if (! Cache::add('tests:reap-scans:requeued:'.$app->id.':'.now()->toDateString(), true, now()->endOfDay())) {
            return null;
        }

        return $this->requester->request($app);
    }

    private function deleteJobsOf(int $scanId): void
    {
        foreach ($this->jobs() as $job) {
            if ($this->scanIdOf($job->payload) === $scanId) {
                $this->queue()->where('id', $job->id)->delete();
            }
        }
    }

    /**
     * The scans the queue still holds a job for, or null when the queue
     * cannot be read (then nothing queued is reaped).
     *
     * @return list<int>|null
     */
    private function queuedScanIds(): ?array
    {
        try {
            return array_values(array_filter(array_map(fn ($job) => $this->scanIdOf($job->payload), $this->jobs())));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** @return list<object{id: int, payload: string}> */
    private function jobs(): array
    {
        return $this->queue()->get(['id', 'payload'])->all();
    }

    private function scanIdOf(string $payload): ?int
    {
        $decoded = json_decode($payload, true);

        if (($decoded['displayName'] ?? null) !== RunScan::class) {
            return null;
        }

        return preg_match('/s:\d+:"App\\\\Models\\\\Scan";s:2:"id";i:(\d+);/', (string) ($decoded['data']['command'] ?? ''), $m) === 1 ? (int) $m[1] : null;
    }

    private function queue(): \Illuminate\Database\Query\Builder
    {
        $config = (array) config('queue.connections.'.config('scanner.queue.connection'));

        return DB::connection($config['connection'] ?? null)->table((string) ($config['table'] ?? 'jobs'));
    }

    private function when(?Carbon $at): string
    {
        return $at === null ? 'it started' : $at->toDateTimeString().' UTC';
    }
}
