<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Where the fleet's checkouts live
    |--------------------------------------------------------------------------
    |
    | The directory holding one checkout per app, named by its production host
    | (`finance.dashcore.com`). An app is only scanned if its checkout is found
    | inside this root after realpath(), so a symlink cannot point a scan at
    | anything else. Unset means nothing is scannable: a root that guesses is
    | not an allowlist.
    |
    */

    'root' => env('SCANNER_ROOT'),

    /*
    |--------------------------------------------------------------------------
    | Scratch space
    |--------------------------------------------------------------------------
    |
    | Each scan runs in a throwaway git worktree of the app's default branch,
    | created here and removed when the scan ends — the app's own working copy
    | and its development database are never touched. Raw probe output is kept
    | beside it for `keep_runs` scans per app, for debugging a verdict.
    |
    */

    'workspace' => env('SCANNER_WORKSPACE', storage_path('scanner')),

    'keep_runs' => (int) env('SCANNER_KEEP_RUNS', 5),

    /*
    |--------------------------------------------------------------------------
    | The test process
    |--------------------------------------------------------------------------
    |
    | Coverage comes from Xdebug, loaded only into the scan's own PHP process.
    | Herd ships one per PHP version; the default finds the one matching the
    | PHP binary below. Scans favour quality over speed — the timeout is long
    | because the machines running this are not in a hurry.
    |
    */

    'php' => env('SCANNER_PHP', PHP_BINARY),

    'xdebug' => env('SCANNER_XDEBUG', sprintf(
        '/Applications/Herd.app/Contents/Resources/xdebug/xdebug-%d%d-%s.so',
        PHP_MAJOR_VERSION,
        PHP_MINOR_VERSION,
        php_uname('m') === 'arm64' ? 'arm64' : 'x86',
    )),

    'composer' => env('SCANNER_COMPOSER', 'composer'),

    // Directories put in front of PATH for everything a workspace runs. The
    // fleet scheduler runs with a minimal PATH, so node, npm and claude
    // (usually under nvm) must be named here to be found at night.
    'path' => env('SCANNER_PATH'),

    'npm' => env('SCANNER_NPM', 'npm'),

    'timeout' => (int) env('SCANNER_TIMEOUT', 7200),

    /*
    |--------------------------------------------------------------------------
    | The test database
    |--------------------------------------------------------------------------
    |
    | Every suite runs against Postgres, whatever its phpunit.xml says, so a
    | query that only works on SQLite shows up as the failure it is in
    | production. Each app gets its own database, dropped and recreated for
    | every scan, on the server this app itself uses.
    |
    */

    'database' => [
        'connection' => env('SCANNER_DB_CONNECTION', 'pgsql'),
        'prefix' => env('SCANNER_DB_PREFIX', 'tests_scan_'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mutation checks
    |--------------------------------------------------------------------------
    |
    | After the suite runs, up to `budget` tested methods per scan have their
    | body replaced with a trivial default and their proving tests re-run. A
    | method whose tests still pass is downgraded to Weak. Verdicts are kept
    | while the method's code is unchanged; survivors are re-checked first,
    | then methods never checked, then the oldest kills after `recheck_days`.
    |
    */

    'mutation' => [
        'budget' => (int) env('SCANNER_MUTANTS_PER_SCAN', 40),
        'timeout' => (int) env('SCANNER_MUTANT_TIMEOUT', 600),
        'recheck_days' => (int) env('SCANNER_MUTATION_RECHECK_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Test builds
    |--------------------------------------------------------------------------
    |
    | A build hands up to `targets` open surfaces of one app to Claude Code,
    | running headless in a worktree branch, then verifies the tests itself
    | and opens a PR for review when they hold up. `publish` false keeps the
    | branch local instead of pushing it. `nightly` queues one build per app
    | each night for apps with no build PR already waiting on review.
    |
    */

    'build' => [
        'claude' => env('SCANNER_CLAUDE', 'claude'),
        'model' => env('SCANNER_BUILD_MODEL'),
        'targets' => (int) env('SCANNER_BUILD_TARGETS', 6),
        'timeout' => (int) env('SCANNER_BUILD_TIMEOUT', 3600),
        'publish' => (bool) env('SCANNER_BUILD_PUBLISH', true),
        'nightly' => (bool) env('SCANNER_BUILD_NIGHTLY', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | The fleet baseline
    |--------------------------------------------------------------------------
    |
    | A checkout of dashcore/testkit, whose bin/vendor-into installs a release
    | into an app. Defaults to `testkit` under the scanner root. `version` pins
    | the release to roll out; unset means the checkout's newest tag.
    |
    */

    'testkit' => [
        'path' => env('SCANNER_TESTKIT_PATH'),
        'version' => env('SCANNER_TESTKIT_VERSION'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Scans run for minutes to hours, so they go on their own connection whose
    | retry_after outlasts the timeout — on the default connection a running
    | scan would be handed to a second worker after 90 seconds.
    |
    */

    'queue' => [
        'connection' => env('SCANNER_QUEUE_CONNECTION', 'scans'),
        'queue' => env('SCANNER_QUEUE', 'scans'),
    ],

];
