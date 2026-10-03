<?php

use App\Simulation\Journeys\Sales\CaptureLead;
use App\Simulation\Journeys\Sales\CloseWonDeal;

return [

    /*
    |--------------------------------------------------------------------------
    | Proving Ground
    |--------------------------------------------------------------------------
    |
    | A never-ending simulation: fake people work through the fake company's
    | apps in a real browser, one journey after another, and every step is
    | recorded with a screenshot. It runs against local copies only — the
    | *.fakecompany.com checkouts — whose data it is free to grow.
    |
    | The worker is `php artisan tests:simulate`, kept alive by the launchd
    | agent com.dashcore.simulation-tests. The page's switch only sets a flag
    | the worker reads; it never starts a process itself.
    |
    */

    'node' => env('SIMULATION_NODE', 'node'),

    'runner' => base_path('simulator/run.mjs'),

    // Screenshots, one directory per run. Kept for the newest `keep_runs`
    // runs; older runs keep their record but lose their pictures. .noindex
    // keeps Spotlight out, as for the scanner.
    'screenshots' => env('SIMULATION_SCREENSHOTS', storage_path('simulation.noindex')),

    'keep_runs' => (int) env('SIMULATION_KEEP_RUNS', 300),

    // Run records older than this are deleted outright.
    'keep_days' => (int) env('SIMULATION_KEEP_DAYS', 14),

    // Seconds between journeys, so a person watching can follow along.
    'pause' => (int) env('SIMULATION_PAUSE', 4),

    'step_timeout_ms' => (int) env('SIMULATION_STEP_TIMEOUT_MS', 15000),

    'run_timeout' => (int) env('SIMULATION_RUN_TIMEOUT', 300),

    // The worker exits after this many runs and launchd starts a fresh one,
    // so it picks up new code and never grows without bound.
    'max_runs' => (int) env('SIMULATION_MAX_RUNS', 200),

    /*
    |--------------------------------------------------------------------------
    | Findings
    |--------------------------------------------------------------------------
    |
    | After every run its failures, server errors, browser errors, clicks with
    | no loading feedback and slow steps become findings. A step slower than
    | slow_ms is slow. A finding is resolved once resolve_after runs in a row
    | could have hit it and did not.
    |
    */

    'findings' => [
        'slow_ms' => (int) env('SIMULATION_SLOW_MS', 3000),
        'resolve_after' => (int) env('SIMULATION_RESOLVE_AFTER', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Targets
    |--------------------------------------------------------------------------
    |
    | The apps journeys run against, and the people who use them. Personas are
    | the users the app's own seeders create (`db:seed` then
    | `db:seed --class=DemoDataSeeder`). Those seeders give every user the
    | literal password "password": a fixture of the fake company, not a secret.
    |
    */

    'targets' => [
        'sales' => [
            'name' => 'Sales',
            'base_url' => env('SIMULATION_SALES_URL', 'http://sales.fakecompany.com.test'),
            'password' => env('SIMULATION_SALES_PASSWORD', 'password'),
            'personas' => [
                ['name' => 'Maya Okafor', 'email' => 'maya.okafor@example.com', 'role' => 'Sales rep'],
                ['name' => 'Daniel Reyes', 'email' => 'daniel.reyes@example.com', 'role' => 'Sales rep'],
                ['name' => 'Priya Natarajan', 'email' => 'priya.natarajan@example.com', 'role' => 'Sales rep'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Journeys
    |--------------------------------------------------------------------------
    |
    | Every journey in rotation, with its weight: a journey weighted 3 runs
    | three times as often as one weighted 1.
    |
    */

    'journeys' => [
        CaptureLead::class => 3,
        CloseWonDeal::class => 2,
    ],

];
