<?php

namespace App\Services;

use App\Enums\ScanStatus;
use App\Models\Scan;
use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * Whether this install's schedule is doing its work, for the heartbeat to
 * say every tick: the workflows tick (the browser ran, the command did not
 * crash) and the scans (something takes queued scans). A site failing its
 * workflow or a suite failing its scan is that site's or that app's news,
 * never the heartbeat's — /fail means this machine's machinery is broken.
 */
class ScheduleHealth
{
    /** What tests:workflows' last scheduled tick found: {at, healthy, note}. */
    public const WORKFLOWS_RESULT = 'workflows.last_result';

    /** When a scan worker last took a scan job (RunScan), slot or no slot. */
    public const SCAN_WORKER_SEEN = 'scans.worker_seen_at';

    /**
     * @return array{healthy: bool, note: string}
     */
    public function check(): array
    {
        $problems = [];
        $notes = [];

        $workflows = Setting::get(self::WORKFLOWS_RESULT);

        if (is_array($workflows) && isset($workflows['at'])) {
            $at = Carbon::parse($workflows['at']);

            if ($at->lt(now()->subMinutes((int) config('workflows.tick_stale_after_minutes')))) {
                $problems[] = 'tests:workflows has not finished a tick since '.$at->toDayDateTimeString().'.';
            } elseif (! ($workflows['healthy'] ?? true)) {
                $problems[] = (string) ($workflows['note'] ?? 'tests:workflows reported a problem.');
            } else {
                $notes[] = (string) ($workflows['note'] ?? 'Workflows ticked.');
            }
        }

        $queued = Scan::query()->where('status', ScanStatus::Queued);
        $running = Scan::query()->whereIn('status', [ScanStatus::Preparing, ScanStatus::Running, ScanStatus::Analysing, ScanStatus::Mutating])->count();
        $waiting = (clone $queued)->count();
        $stalledSince = now()->subMinutes((int) config('scanner.stalled_after_minutes'));
        $seen = Setting::get(self::SCAN_WORKER_SEEN);
        $workerSeen = is_string($seen) && Carbon::parse($seen)->gte($stalledSince);

        if ($running === 0 && ! $workerSeen && (clone $queued)->where('created_at', '<', $stalledSince)->exists()) {
            $problems[] = "{$waiting} scan(s) queued and nothing has taken one for ".config('scanner.stalled_after_minutes').' minutes: are this install\'s scan workers (tests:host-runtime) running?';
        }

        $notes[] = "Scans: {$waiting} queued, {$running} running.";

        return ['healthy' => $problems === [], 'note' => implode("\n", $problems === [] ? $notes : $problems)];
    }

    public static function scanWorkerSeen(): void
    {
        Setting::set(self::SCAN_WORKER_SEEN, now()->toIso8601String());
    }
}
