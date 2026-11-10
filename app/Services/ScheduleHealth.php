<?php

namespace App\Services;

use App\Enums\ScanKind;
use App\Enums\ScanStatus;
use App\Models\FleetApp;
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

    /** The problem key for scans gone stale; Console's report files it as its own finding. */
    public const SCANS_STALE = 'scans-stale';

    /**
     * @return array{healthy: bool, note: string, problems: list<string>}
     */
    public function check(): array
    {
        $problems = [];
        $keys = [];
        $notes = [];

        $workflows = Setting::get(self::WORKFLOWS_RESULT);

        if (is_array($workflows) && isset($workflows['at'])) {
            $at = Carbon::parse($workflows['at']);

            if ($at->lt(now()->subMinutes((int) config('workflows.tick_stale_after_minutes')))) {
                $problems[] = 'tests:workflows has not finished a tick since '.$at->toDayDateTimeString().'.';
                $keys[] = 'workflows-stalled';
            } elseif (! ($workflows['healthy'] ?? true)) {
                $problems[] = (string) ($workflows['note'] ?? 'tests:workflows reported a problem.');
                $keys[] = 'workflows-failing';
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
            $keys[] = 'scans-stalled';
        }

        $stale = $this->scansStale();

        if ($stale !== null) {
            $problems[] = $stale;
            $keys[] = self::SCANS_STALE;
        }

        $notes[] = "Scans: {$waiting} queued, {$running} running.";

        return ['healthy' => $problems === [], 'note' => implode("\n", $problems === [] ? $notes : $problems), 'problems' => $keys];
    }

    /**
     * Why the scans are stale, or null when they are not: the nightly is on,
     * the roster has enabled apps, and no main scan has completed for
     * `stale_after_hours` — counted from the roster's first enabled app when
     * none ever has. A nightly that queued nothing (the scanner root lost,
     * REQ-476) looked healthy with "0 queued, 0 running"; this is what says so.
     */
    /**
     * Why the nightly scan is not running here, when it is switched off on
     * an install with enabled apps to scan; null when it is on (or there is
     * nothing to scan). The schedule reads SCANNER_NIGHTLY from this
     * checkout's .env: the launchd environment Console writes for the
     * workers never reaches `schedule:run`, so a worker plist saying "true"
     * does not switch it on. The 7 and 8 Nov 2026 nightlies queued nothing
     * because the .env had said false since 31 Oct, and scansStale() — on
     * only with the nightly — stayed quiet (REQ-683).
     */
    public function nightlyOff(): ?string
    {
        if (config('scanner.nightly')) {
            return null;
        }

        if (! FleetApp::query()->real()->where('is_enabled', true)->exists()) {
            return null;
        }

        $last = Scan::query()->where('kind', ScanKind::Main)->where('status', ScanStatus::Completed)->max('finished_at');

        return 'SCANNER_NIGHTLY is false in '.base_path('.env').', so the schedule queues no nightly tests:scan'
            .' (a worker\'s launchd environment does not reach schedule:run). '
            .($last === null ? 'No main scan has ever completed here.' : 'The last main scan finished '.Carbon::parse($last)->toDayDateTimeString().'.');
    }

    public function scansStale(): ?string
    {
        if (! config('scanner.nightly')) {
            return null;
        }

        $apps = FleetApp::query()->real()->where('is_enabled', true);

        if (! (clone $apps)->exists()) {
            return null;
        }

        $hours = (int) config('scanner.stale_after_hours');
        $last = Scan::query()->where('kind', ScanKind::Main)->where('status', ScanStatus::Completed)->max('finished_at');
        $since = Carbon::parse($last ?? (clone $apps)->min('created_at'));

        if ($since->gte(now()->subHours($hours))) {
            return null;
        }

        return $last === null
            ? "No main scan has ever completed here, and the roster has had enabled apps since {$since->toDayDateTimeString()}: is the nightly tests:scan queuing anything?"
            : "No main scan has completed in {$hours} hours (the last finished {$since->toDayDateTimeString()}): is the nightly tests:scan queuing anything?";
    }

    public static function scanWorkerSeen(): void
    {
        Setting::set(self::SCAN_WORKER_SEEN, now()->toIso8601String());
    }
}
