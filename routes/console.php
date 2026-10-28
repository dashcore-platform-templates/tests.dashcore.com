<?php

use App\Jobs\FlyLaunch;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The roster first, so the nightly scan covers the fleet as it is today.
Schedule::command('tests:sync-fleet')->dailyAt('00:30')->withoutOverlapping();

// Every scannable app, every night, from its default branch. Every fleet's
// tests shares the Mac, so each starts at its own SCANNER_NIGHTLY_AT.
// Off with SCANNER_NIGHTLY=false where the scan workers are paused.
Schedule::command('tests:scan')->dailyAt((string) config('scanner.nightly_at'))->withoutOverlapping()
    ->when(fn () => (bool) config('scanner.nightly'));

// Scans and builds run on their own long-lived worker, the launchd agent
// com.dashcore.queue-tests (KeepAlive) — not from here. The fleet host runs
// this schedule as a periodic launchd job, and launchd ends every process the
// tick started when the tick ends, so a worker started here dies mid-scan.

// A build for each app with gaps and nothing already running or waiting on
// review — so at most one PR per app waits for a person. Hourly rather than
// once a night: a single missed tick of a daily schedule loses a whole day,
// and a reviewed PR is followed by the next one within the hour. Off unless
// SCANNER_BUILD_NIGHTLY is set.
Schedule::command('tests:build')->hourlyAt(20)->withoutOverlapping()
    ->when(fn () => (bool) config('scanner.build.nightly'));

// Every new push to an open PR is scanned and reported as a commit status.
Schedule::command('tests:scan-prs')->everyTenMinutes()->withoutOverlapping()
    ->when(fn () => (bool) config('scanner.pull_requests.enabled'));

// Follow the PRs builds opened: merged, closed, or still waiting.
Schedule::command('tests:sync-builds')->hourly()->withoutOverlapping();

Schedule::command('tests:prune')->dailyAt('05:00')->withoutOverlapping();

// Browser-test workflows built on /workflows: each one due is walked here,
// in the tick itself (seconds to a minute each), never in the background.
Schedule::command('tests:workflows')->everyMinute()->withoutOverlapping(30);

// A whole new client brought on across the practice fleet every night
// (`tests:launch`), so the client journey map is never more than a day old.
// On the builds queue: a launch builds a fleet and runs for many minutes.
// Off with SIMULATION_LAUNCH_NIGHTLY=false.
Schedule::job(new FlyLaunch)->dailyAt('02:30')
    ->when(fn () => (bool) config('simulation.launch_nightly'));
