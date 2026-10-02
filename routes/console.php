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

// Scans run on their own worker, started here when there is work and gone
// when there is none, so the host needs no extra launch agent. The lock
// outlives the longest night of scans so a second worker never starts.
Schedule::command('queue:work scans --queue=scans --stop-when-empty --tries=1 --sleep=3')
    ->everyMinute()
    ->withoutOverlapping(720)
    ->runInBackground();

Schedule::command('tests:prune')->dailyAt('05:00')->withoutOverlapping();
