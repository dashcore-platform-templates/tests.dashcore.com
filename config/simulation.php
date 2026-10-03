<?php

use App\Simulation\Journeys\Finance\AddCategory;
use App\Simulation\Journeys\Finance\AddRecurringIncome;
use App\Simulation\Journeys\Finance\RecordExpense;
use App\Simulation\Journeys\Finance\RecordIncome;
use App\Simulation\Journeys\Finance\ReviewBooks;
use App\Simulation\Journeys\Sales\AdvanceDeal;
use App\Simulation\Journeys\Sales\AssignLead;
use App\Simulation\Journeys\Sales\CaptureLead;
use App\Simulation\Journeys\Sales\CloseLostDeal;
use App\Simulation\Journeys\Sales\CloseWonDeal;
use App\Simulation\Journeys\Sales\FollowUpLead;
use App\Simulation\Journeys\Sales\OpenOpportunity;
use App\Simulation\Journeys\Sales\ReviewPipeline;
use App\Simulation\Pulse\FinancePulse;
use App\Simulation\Pulse\SalesPulse;
use App\Simulation\Pulse\TablePulse;

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

    // …or after this many seconds, whichever comes first — an idle worker
    // (switch off) runs nothing, so without a clock it would never restart.
    'max_seconds' => (int) env('SIMULATION_MAX_SECONDS', 3600),

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

        'finance' => [
            'name' => 'Finance',
            'base_url' => env('SIMULATION_FINANCE_URL', 'http://finance.fakecompany.com.test'),
            'checkout' => env('SIMULATION_FINANCE_CHECKOUT', env('SCANNER_ROOT') ? rtrim(env('SCANNER_ROOT'), '/').'/finance.fakecompany.com' : null),
            // Finance runs on MySQL (Herd's, root with no password locally).
            'database' => env('SIMULATION_FINANCE_DATABASE', 'finance_fakecompany_com'),
            'connection' => [
                'driver' => 'mysql',
                'host' => env('SIMULATION_FINANCE_DB_HOST', '127.0.0.1'),
                'port' => env('SIMULATION_FINANCE_DB_PORT', '3306'),
                'username' => env('SIMULATION_FINANCE_DB_USERNAME', 'root'),
                'password' => env('SIMULATION_FINANCE_DB_PASSWORD', ''),
            ],
            'timezone' => env('SIMULATION_FINANCE_TIMEZONE', 'America/Los_Angeles'),
            'pulse' => FinancePulse::class,
            // Fortify's plain login form, not a Livewire one.
            'login' => [
                'email' => 'input[name="email"]',
                'password' => 'input[name="password"]',
                'submit' => 'button[data-test="login-button"]',
                'landed' => 'Overview',
            ],
            'password' => env('SIMULATION_FINANCE_PASSWORD', 'password'),
            // Its seeders make only a read-only viewer, so tests:prepare-target
            // also creates these people with the app role each needs.
            'personas' => [
                ['name' => 'Fiona Grant', 'email' => 'fiona.grant@example.com', 'role' => 'Finance lead', 'app_role' => 'admin'],
                ['name' => 'Omar Haddad', 'email' => 'omar.haddad@example.com', 'role' => 'Bookkeeper', 'app_role' => 'editor'],
                ['name' => 'Lena Park', 'email' => 'lena.park@example.com', 'role' => 'Bookkeeper', 'app_role' => 'editor'],
            ],
            'prepare' => ['migrate --force', 'db:seed --force', 'db:seed --class=WhatChangedDemoSeeder --force'],
        ],

        'hr' => [
            'name' => 'HR',
            'base_url' => env('SIMULATION_HR_URL', 'http://hr.fakecompany.com.test'),
            'checkout' => env('SIMULATION_HR_CHECKOUT', env('SCANNER_ROOT') ? rtrim(env('SCANNER_ROOT'), '/').'/hr.fakecompany.com' : null),
            'database' => env('SIMULATION_HR_DATABASE', 'hr_fakecompany_com'),
            'timezone' => env('SIMULATION_HR_TIMEZONE', 'America/Los_Angeles'),
            'login' => ['email' => 'input[name="email"]', 'password' => 'input[name="password"]', 'submit' => 'button[data-test="login-button"]', 'landed' => 'Dashboard'],
            'password' => env('SIMULATION_HR_PASSWORD', 'password'),
            // Read only for now: the fake HR app mails through Resend for real
            // and its people have @dashcore.com addresses, so requesting or
            // approving time off, inviting or assigning training would reach
            // real inboxes. The tour changes nothing; signing in sends nothing.
            'explore_off' => 'the fake HR app sends real email (MAIL_MAILER=resend) to @dashcore.com addresses; switch it to log first.',
            'personas' => [
                ['name' => 'Jane Holloway', 'email' => 'jane@dashcore.com', 'role' => 'HR manager'],
                ['name' => 'Marcus Reyes', 'email' => 'marcus@dashcore.com', 'role' => 'Manager'],
            ],
            // Every module is off on a fresh install (Pennant, per company);
            // switch them on for the fake company before touring.
            'tour' => [
                // Managers are refused the employee list (403) by design.
                'roles' => ['HR manager'],
                'pages' => [
                    ['path' => '/employees', 'see' => 'Employees', 'name' => 'the employees'],
                    ['path' => '/pto', 'see' => 'PTO & Leave', 'name' => 'PTO and leave'],
                    ['path' => '/time', 'see' => 'Time & Attendance', 'name' => 'time and attendance'],
                    ['path' => '/timesheets', 'see' => 'Timesheets', 'name' => 'the timesheets'],
                    ['path' => '/reports', 'see' => 'Reports', 'name' => 'the reports'],
                ],
            ],
            'pulse' => TablePulse::class,
            'pulse_metrics' => [
                ['label' => 'Active employees', 'table' => 'employees', 'where' => ['employment_status' => 'active']],
                ['label' => 'Time off waiting', 'table' => 'time_off_requests', 'where' => ['status' => 'submitted']],
                ['label' => 'Timesheets to approve', 'table' => 'timesheets', 'where' => ['status' => 'submitted']],
                ['label' => 'Onboardings', 'table' => 'employee_onboardings'],
                ['label' => 'Time entries', 'table' => 'time_entries'],
            ],
            'prepare' => ['migrate --force', 'db:seed --force'],
        ],

        'marketing' => [
            'name' => 'Marketing',
            'base_url' => env('SIMULATION_MARKETING_URL', 'http://marketing.fakecompany.com.test'),
            'checkout' => env('SIMULATION_MARKETING_CHECKOUT', env('SCANNER_ROOT') ? rtrim(env('SCANNER_ROOT'), '/').'/marketing.fakecompany.com' : null),
            'database' => env('SIMULATION_MARKETING_DATABASE', 'marketing_fakecompany_com'),
            'timezone' => env('SIMULATION_MARKETING_TIMEZONE', 'America/Los_Angeles'),
            // Plain Blade forms, no Livewire.
            'login' => ['email' => 'input[name="email"]', 'password' => 'input[name="password"]', 'submit' => 'form button[type="submit"]', 'landed' => 'Scoreboard'],
            'password' => env('SIMULATION_MARKETING_PASSWORD', 'password'),
            // Its seeder makes one owner account with a real address; the
            // simulation's team is its own.
            'personas' => [
                ['name' => 'Rae Owens', 'email' => 'rae.owens@example.com', 'role' => 'Marketing lead', 'app_role' => 'owner'],
                ['name' => 'Sol Mendez', 'email' => 'sol.mendez@example.com', 'role' => 'Supervisor', 'app_role' => 'supervisor'],
                ['name' => 'Mara Quinn', 'email' => 'mara.quinn@example.com', 'role' => 'Marketer', 'app_role' => 'member'],
            ],
            'explore_notes' => [
                'It is server-rendered Blade with controllers and plain forms — no Livewire, so no wire: selectors; use ids, name attributes and button text.',
                'Never push to Buffer, generate with the AI studio or autopilot, connect Instagram, text through Twilio (quick reels, quick story), upload files, sync ads, refresh the news feed or send a test email.',
                'Never use the admin pages to invite, impersonate or delete users.',
            ],
            'tour' => [
                'pages' => [
                    ['path' => '/game-plan', 'see' => 'The Action Plan', 'name' => 'the action plan'],
                    ['path' => '/goals', 'see' => 'aiming at', 'name' => 'the goals'],
                    ['path' => '/pipeline', 'see' => 'From lead to paying customer', 'name' => 'the pipeline'],
                    ['path' => '/calendar', 'see' => 'week by week', 'name' => 'the calendar'],
                    ['path' => '/performance', 'see' => 'The numbers', 'name' => 'the performance numbers'],
                    ['path' => '/library', 'see' => 'Everything', 'name' => 'the library'],
                ],
            ],
            'pulse' => TablePulse::class,
            'pulse_metrics' => [
                ['label' => 'Leads', 'table' => 'leads'],
                ['label' => 'Customers', 'table' => 'leads', 'where' => ['stage' => 'customer']],
                ['label' => 'Campaigns', 'table' => 'campaigns'],
                ['label' => 'Assets', 'table' => 'assets'],
                ['label' => 'Plan slots', 'table' => 'plan_slots'],
            ],
            'prepare' => ['migrate --force', 'db:seed --force'],
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
        RecordExpense::class => 4,
        RecordIncome::class => 2,
        AddRecurringIncome::class => 1,
        AddCategory::class => 1,
        ReviewBooks::class => 1,
    ],

];
