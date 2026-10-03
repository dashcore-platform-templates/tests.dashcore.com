<?php

use App\Simulation\Journeys\Sales\AdvanceDeal;
use App\Simulation\Journeys\Sales\AssignLead;
use App\Simulation\Journeys\Sales\CaptureLead;
use App\Simulation\Journeys\Sales\CloseLostDeal;
use App\Simulation\Journeys\Sales\CloseWonDeal;
use App\Simulation\Journeys\Sales\FollowUpLead;
use App\Simulation\Journeys\Sales\OpenOpportunity;
use App\Simulation\Journeys\Sales\ReviewPipeline;
use App\Simulation\Pulse\SalesPulse;

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

    // Seconds before a finished journey's place is taken by the next one, so
    // a person watching can follow along.
    'pause' => (int) env('SIMULATION_PAUSE', 4),

    // How many journeys run at once until someone changes it on the page —
    // people working side by side, each in their own browser. Up to 6.
    'concurrency' => (int) env('SIMULATION_CONCURRENCY', 1),

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
        // Errors seen in an app's log or failed jobs are not tied to a run,
        // so they need a longer quiet spell — this many passed runs on that
        // app without them — to count as resolved.
        'resolve_monitored_after' => (int) env('SIMULATION_RESOLVE_MONITORED_AFTER', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Exploring and trials
    |--------------------------------------------------------------------------
    |
    | The explorer is Claude Code, headless and read only, drafting journeys
    | from a target's source (`tests:explore`). A draft goes on trial at
    | trials.weight; trials.passes passes in a row make it active, and
    | trials.failures failures hold it back for a person to look at.
    |
    */

    'explore' => [
        'claude' => env('SIMULATION_CLAUDE', env('SCANNER_CLAUDE', 'claude')),
        'model' => env('SIMULATION_EXPLORE_MODEL'),
        'budget_usd' => (float) env('SIMULATION_EXPLORE_BUDGET_USD', 3),
        'timeout' => (int) env('SIMULATION_EXPLORE_TIMEOUT', 1800),
        'drafts' => (int) env('SIMULATION_EXPLORE_DRAFTS', 3),
    ],

    'trials' => [
        'passes' => (int) env('SIMULATION_TRIAL_PASSES', 3),
        'failures' => (int) env('SIMULATION_TRIAL_FAILURES', 2),
        'weight' => (int) env('SIMULATION_TRIAL_WEIGHT', 4),
    ],

    /*
    |--------------------------------------------------------------------------
    | Targets
    |--------------------------------------------------------------------------
    |
    | The apps journeys run against, the people who use them, and how to read
    | each one's vital signs for the page's pulse panel. Personas are
    | the users the app's own seeders create (`db:seed` then
    | `db:seed --class=DemoDataSeeder`). Those seeders give every user the
    | literal password "password": a fixture of the fake company, not a secret.
    |
    */

    'targets' => [
        'sales' => [
            'name' => 'Sales',
            'base_url' => env('SIMULATION_SALES_URL', 'http://sales.fakecompany.com.test'),
            // Its source, for the explorer to read.
            'checkout' => env('SIMULATION_SALES_CHECKOUT', env('SCANNER_ROOT') ? rtrim(env('SCANNER_ROOT'), '/').'/sales.fakecompany.com' : null),
            // Its database, on this app's own Postgres server, read by the
            // pulse panel — never written.
            'database' => env('SIMULATION_SALES_DATABASE', 'sales_fakecompany_com'),
            // The timezone its timestamps are stored in (its APP_TIMEZONE).
            'timezone' => env('SIMULATION_SALES_TIMEZONE', 'America/Los_Angeles'),
            'pulse' => SalesPulse::class,
            'password' => env('SIMULATION_SALES_PASSWORD', 'password'),
            'personas' => [
                ['name' => 'Admin', 'email' => 'admin@example.com', 'role' => 'Owner'],
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
        FollowUpLead::class => 4,
        OpenOpportunity::class => 2,
        AdvanceDeal::class => 3,
        CloseWonDeal::class => 2,
        CloseLostDeal::class => 1,
        AssignLead::class => 2,
        ReviewPipeline::class => 1,
    ],

];
