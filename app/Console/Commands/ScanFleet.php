<?php

namespace App\Console\Commands;

use App\Enums\ScanStatus;
use App\Models\FleetApp;
use App\Models\Setting;
use App\Scanning\Scanner;
use App\Scanning\ScanRequester;
use Illuminate\Console\Command;

class ScanFleet extends Command
{
    protected $signature = 'tests:scan
                            {app?* : Fleet app keys to scan (default: every scannable app)}
                            {--now : Run in this process instead of queueing — for debugging one app}
                            {--last : Queue nothing: exit 0 only when the newest main scan of every scannable app completed}';

    /** What the last whole-roster run queued: {at, failed, queued, already, enabled, note}, for Console's report. */
    public const LAST_REQUEST = 'scans.last_request';

    protected $description = 'Scan fleet apps: run each suite under the probe and record which surfaces are tested';

    public function handle(ScanRequester $requester, Scanner $scanner): int
    {
        if ($this->option('last')) {
            return $this->last();
        }

        $keys = (array) $this->argument('app');

        $apps = FleetApp::query()
            ->real()
            ->when($keys !== [], fn ($q) => $q->whereIn('key', $keys))
            ->orderBy('key')
            ->get();

        if ($keys !== [] && $apps->count() !== count($keys)) {
            $this->components->error('Not in the fleet: '.implode(', ', array_diff($keys, $apps->pluck('key')->all())));

            return self::FAILURE;
        }

        $queued = 0;
        $already = 0;
        $missing = [];

        foreach ($apps as $app) {
            if (! $app->isScannable()) {
                if ($app->is_enabled) {
                    $missing[] = $app->key;
                }

                $this->components->twoColumnDetail($app->key, '<fg=gray>no local checkout</>');

                continue;
            }

            $scan = $requester->request($app, queue: ! $this->option('now'));

            if ($scan === null) {
                $already++;
                $this->components->twoColumnDetail($app->key, '<fg=yellow>already scanning</>');

                continue;
            }

            $queued++;

            if (! $this->option('now')) {
                $this->components->twoColumnDetail($app->key, '<fg=green>queued</>');

                continue;
            }

            $scan = $scanner->run($scan);
            $this->components->twoColumnDetail(
                $app->key,
                $scan->error === null
                    ? "<fg=green>{$scan->score}%</> {$scan->surfaces_tested}/{$scan->surfaces_total} surfaces, {$scan->tests_failed} failing tests"
                    : '<fg=red>failed</> '.$scan->error,
            );
        }

        return $this->settle($apps->where('is_enabled', true)->count(), $queued, $already, $missing, record: $keys === []);
    }

    /**
     * A run that queued nothing while enabled apps wait to be scanned is a
     * failure, said out loud: exit non-zero and, for a whole-roster run (the
     * nightly), a record Console's report files as tests:scan-queued-nothing.
     * Apps already scanning are not a failure — their scan is under way.
     * When every enabled app lacks a checkout the scanner root is lost
     * (REQ-476), so that fails even if some other app was still scanning.
     *
     * @param  list<string>  $missing
     */
    private function settle(int $enabled, int $queued, int $already, array $missing, bool $record): int
    {
        $note = null;

        if ($enabled > 0 && count($missing) === $enabled) {
            $note = "None of the {$enabled} enabled app(s) has a checkout under the scanner root (".(config('scanner.root') ?: 'SCANNER_ROOT is unset').'): run php artisan tests:sync-fleet, and check SCANNER_ROOT.';
        } elseif ($enabled > 0 && $queued === 0 && $already === 0) {
            $note = "tests:scan queued nothing with {$enabled} enabled app(s) on the roster.";
        }

        if ($record) {
            Setting::set(self::LAST_REQUEST, [
                'at' => now()->toIso8601String(),
                'failed' => $note !== null,
                'queued' => $queued,
                'already' => $already,
                'enabled' => $enabled,
                'note' => $note ?? "Queued {$queued} scan(s); {$already} already scanning.",
            ]);
        }

        if ($note !== null) {
            $this->components->error($note);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Console's check that the scan it asked for has run: the newest main
     * scan of every scannable app completed. No scannable app at all is a
     * failure too — there is nothing to say the fleet was scanned.
     */
    private function last(): int
    {
        $apps = FleetApp::query()->real()->with('latestScan')->orderBy('key')->get()->filter->isScannable();

        if ($apps->isEmpty()) {
            $this->components->error('No app on the roster is scannable here: run php artisan tests:sync-fleet, and check SCANNER_ROOT.');

            return self::FAILURE;
        }

        $done = true;

        foreach ($apps as $app) {
            $scan = $app->latestScan;
            $completed = $scan?->status === ScanStatus::Completed;
            $done = $done && $completed;

            $this->components->twoColumnDetail($app->key, match (true) {
                $scan === null => '<fg=red>never scanned</>',
                $completed => '<fg=green>completed</> #'.$scan->id.' '.$scan->finished_at?->diffForHumans(),
                default => '<fg=yellow>'.$scan->status->label().'</> #'.$scan->id,
            });
        }

        return $done ? self::SUCCESS : self::FAILURE;
    }
}
