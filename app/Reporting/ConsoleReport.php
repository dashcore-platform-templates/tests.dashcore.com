<?php

namespace App\Reporting;

use App\Enums\ScanStatus;
use App\Models\FleetApp;
use App\Models\JourneyRun;
use App\Models\Setting;
use App\Services\Install;
use App\Workflows\Heartbeat;
use Throwable;

/**
 * What this install tells Console (`console:report --json`, Console's one
 * contract for every source — its docs/PLAN.md): the fleet's failed scans,
 * coverage regressions, failing or drifted customer journeys and a silent or
 * failing heartbeat, each with a fingerprint that stays the same while the
 * problem lasts. Nothing wrong means no findings. A part that could not be
 * read makes the report incomplete, so Console never takes a gap for a fix.
 */
class ConsoleReport
{
    /** The heartbeat counts as silent after this long without a tick. */
    public const SILENT_AFTER_MINUTES = 10;

    /**
     * @return array{source: string, generated_at: string, complete: bool, findings: list<array{fingerprint: string, app: ?string, severity: string, title: string, detail: ?string, url: ?string}>, facts: array<string, mixed>}
     */
    public function build(): array
    {
        $findings = [];
        $complete = true;

        foreach (['scans', 'journeys', 'heartbeat'] as $part) {
            try {
                array_push($findings, ...$this->{$part}());
            } catch (Throwable $e) {
                report($e);
                $complete = false;
            }
        }

        return [
            'source' => 'tests',
            'generated_at' => now()->utc()->toIso8601ZuluString(),
            'complete' => $complete,
            'findings' => $findings,
            'facts' => [
                'install' => ['fleet' => Install::fleet(), 'host' => Install::host(), 'home' => Install::isHome()],
                'last_tick' => Heartbeat::lastTick()?->utc()->toIso8601ZuluString(),
            ],
        ];
    }

    /** @return list<array{fingerprint: string, app: ?string, severity: string, title: string, detail: ?string, url: ?string}> */
    private function scans(): array
    {
        $findings = [];

        foreach (FleetApp::query()->with(['latestScan', 'latestCompletedScan'])->orderBy('key')->get() as $app) {
            $latest = $app->latestScan;
            $url = route('fleet.show', $app->key);

            if ($latest?->status === ScanStatus::Failed) {
                $findings[] = $this->finding("tests:scan-failed:{$app->key}", $app->host, 'high',
                    "The {$app->name} scan failed",
                    trim('Scan #'.$latest->id.' failed '.$latest->finished_at?->diffForHumans().': '.(string) $latest->error),
                    route('scans.show', $latest->id));
            }

            $completed = $app->latestCompletedScan;

            if ($completed !== null && $completed->regressions() > 0) {
                $findings[] = $this->finding("tests:regressions:{$app->key}", $app->host, 'medium',
                    "{$app->name} lost test coverage",
                    sprintf('Scan #%d of %s: %d surface(s) lost coverage, %d new surface(s) untested.', $completed->id, substr((string) $completed->commit_sha, 0, 7), $completed->surfaces_lost, $completed->surfaces_new_untested),
                    $url);
            }
        }

        return $findings;
    }

    /** @return list<array{fingerprint: string, app: ?string, severity: string, title: string, detail: ?string, url: ?string}> */
    private function journeys(): array
    {
        $findings = [];
        $url = route('simulation.journey-map');
        $latest = JourneyRun::query()
            ->whereIn('id', JourneyRun::query()->selectRaw('max(id)')->groupBy('journey', 'fleet'))
            ->orderBy('journey')
            ->get();

        foreach ($latest as $run) {
            $name = config("simulation.customer_journeys.{$run->journey}.name", $run->journey);

            if (! $run->passed()) {
                $findings[] = $this->finding("tests:journey-{$run->outcome}:{$run->fleet}:{$run->journey}", null, $run->outcome === JourneyRun::FAILED ? 'high' : 'medium',
                    "The {$name} journey ".($run->outcome === JourneyRun::FAILED ? 'failed' : 'was refused')." on the {$run->fleet} stand-ins",
                    $run->error, $url);
            }

            if ($run->drifted()) {
                $findings[] = $this->finding("tests:journey-drifted:{$run->fleet}:{$run->journey}", null, 'medium',
                    "The {$name} journey walked stand-ins off the live release",
                    'Not on the live release: '.collect($run->drift)->map(fn (array $app) => "{$app['app']} (live {$app['live']})")->implode(', ').". Run php artisan tests:pin-stand-ins {$run->fleet}.",
                    $url);
            }
        }

        return $findings;
    }

    /** @return list<array{fingerprint: string, app: ?string, severity: string, title: string, detail: ?string, url: ?string}> */
    private function heartbeat(): array
    {
        $last = Heartbeat::lastTick();

        if ($last === null || $last->lt(now()->subMinutes(self::SILENT_AFTER_MINUTES))) {
            return [$this->finding('tests:heartbeat-silent', null, 'high',
                'The tests schedule is silent',
                $last === null ? 'tests:heartbeat has never ticked here: is this install on the fleet scheduler?' : 'Its last tick was '.$last->diffForHumans().': no scan, PR scan or workflow is being run.',
                null)];
        }

        $beat = Setting::get(Heartbeat::LAST_BEAT);

        if (is_array($beat) && ($beat['healthy'] ?? true) === false) {
            return [$this->finding('tests:heartbeat-failing', null, 'high',
                'The tests schedule is ticking but its machinery is broken',
                (string) ($beat['note'] ?? ''),
                null)];
        }

        return [];
    }

    /** @return array{fingerprint: string, app: ?string, severity: string, title: string, detail: ?string, url: ?string} */
    private function finding(string $fingerprint, ?string $app, string $severity, string $title, ?string $detail, ?string $url): array
    {
        return ['fingerprint' => $fingerprint, 'app' => $app, 'severity' => $severity, 'title' => $title, 'detail' => $detail === '' ? null : $detail, 'url' => $url];
    }
}
