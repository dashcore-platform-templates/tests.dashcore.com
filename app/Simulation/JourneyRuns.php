<?php

namespace App\Simulation;

use App\Enums\FindingKind;
use App\Enums\FindingStatus;
use App\Models\JourneyRun;
use App\Models\SimulationFinding;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Keeps each customer journey walk (`tests:journey`): its outcome, and the
 * stand-ins that were not on what their fleet runs live. Each drifted
 * stand-in is a finding on the stand-in fleet, resolved by the next walk
 * that finds it back on the live release. Keeping the record never breaks
 * the walk it records.
 */
class JourneyRuns
{
    public function __construct(private readonly Findings $findings) {}

    /**
     * @param  list<array{app: string, checkout: string, live: ?string, live_sha: ?string, actual_sha: ?string}>  $drift
     * @param  list<string>  $surveyed  the apps whose drift was looked at
     */
    public function record(string $journey, string $fleet, string $outcome, array $drift, array $surveyed, ?string $error, Carbon $started): ?JourneyRun
    {
        try {
            return DB::transaction(function () use ($journey, $fleet, $outcome, $drift, $surveyed, $error, $started) {
                $run = JourneyRun::create([
                    'journey' => $journey,
                    'fleet' => $fleet,
                    'outcome' => $outcome,
                    'drift' => $drift === [] ? null : $drift,
                    'error' => $error,
                    'started_at' => $started,
                    'finished_at' => now(),
                ]);

                foreach ($drift as $app) {
                    $this->findings->recordMonitored(
                        $fleet,
                        FindingKind::Drift,
                        'drift|'.$app['app'],
                        "The {$app['app']} stand-in is not on {$app['live']}, the release its fleet runs live",
                        sprintf(
                            '%s is at %s; live is %s (%s). Journeys walked on it prove the stand-in, not what clients use. Run php artisan tests:pin-stand-ins %s.',
                            basename($app['checkout']),
                            substr((string) $app['actual_sha'], 0, 7) ?: 'nothing',
                            $app['live'],
                            $app['live_sha'] === null ? 'not in the checkout' : substr($app['live_sha'], 0, 7),
                            $fleet,
                        ),
                    );
                }

                $settled = array_diff($surveyed, array_column($drift, 'app'));

                SimulationFinding::query()
                    ->where('kind', FindingKind::Drift)
                    ->where('target', $fleet)
                    ->where('status', FindingStatus::Open)
                    ->whereIn('fingerprint', array_map(fn (string $app) => "{$fleet}|drift|{$app}", $settled))
                    ->update(['status' => FindingStatus::Resolved, 'resolved_at' => now()]);

                return $run;
            });
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
