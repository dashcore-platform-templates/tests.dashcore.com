<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The roster first, so the nightly scan covers the fleet as it is today.
Schedule::command('tests:sync-fleet')->dailyAt('00:30')->withoutOverlapping();

// Every scannable app, every night, from its default branch.
Schedule::command('tests:scan')->dailyAt('01:00')->withoutOverlapping();

// Scans and builds run on their own long-lived worker, the launchd agent
// com.dashcore.queue-tests (KeepAlive) — not from here. The fleet host runs
// this schedule as a periodic launchd job, and launchd ends every process the
// tick started when the tick ends, so a worker started here dies mid-scan.

// One build per app with gaps and nothing already waiting on review, after
// the scans it plans from. Off unless SCANNER_BUILD_NIGHTLY is set.
Schedule::command('tests:build')->dailyAt('03:00')->withoutOverlapping()
    ->when(fn () => (bool) config('scanner.build.nightly'));

// Follow the PRs builds opened: merged, closed, or still waiting.
Schedule::command('tests:sync-builds')->hourly()->withoutOverlapping();

Schedule::command('tests:prune')->dailyAt('05:00')->withoutOverlapping();
