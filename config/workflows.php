<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Workflows
    |--------------------------------------------------------------------------
    |
    | Browser tests an owner or admin builds here, as records: a start URL,
    | steps, how often to walk them and who hears when one breaks. Nothing
    | here is shipped with the app — each install keeps its own, and one
    | with none simply has an empty list. They are walked by the same
    | browser runner as the Proving Ground (simulation.node / .runner).
    |
    */

    // What [[email]] becomes in a step, fresh each run: {random} is twelve
    // random characters. A form that mails the address it is given will
    // bounce on example.com; point this at an inbox you own (plus-
    // addressed) to keep the sender's reputation clean.
    'fake_email' => env('WORKFLOWS_FAKE_EMAIL', 'workflow+{random}@example.com'),

    // Screenshots, one directory per run, kept for each workflow's newest
    // `keep_runs` runs; older runs keep their record but lose the pictures.
    'screenshots' => env('WORKFLOWS_SCREENSHOTS', storage_path('workflows.noindex')),

    'keep_runs' => (int) env('WORKFLOWS_KEEP_RUNS', 50),

    // Run records older than this are deleted outright.
    'prune_days' => (int) env('WORKFLOWS_PRUNE_DAYS', 90),

    // Lines in the workflows' log (runs, alerts, changes, schedule gaps)
    // are kept this long — longer than the runs they point at.
    'event_days' => (int) env('WORKFLOWS_EVENT_DAYS', 365),

    'step_timeout_ms' => (int) env('WORKFLOWS_STEP_TIMEOUT_MS', 20000),

    'run_timeout' => (int) env('WORKFLOWS_RUN_TIMEOUT', 180),

    // A Healthchecks.io-style ping URL: pinged every tick (tests:heartbeat),
    // at /fail when the scans' or workflows' machinery is broken. The service texts when pings
    // fail or stop — so it also catches this machine being off. Normally
    // unset: the URL is read from Bitwarden through keys
    // (tests.dashcore.com__WORKFLOWS_HEARTBEAT_URL__local). This overrides it.
    'heartbeat_url' => env('WORKFLOWS_HEARTBEAT_URL'),

    // The keys.dashcore.com checkout that reads it.
    'keys_checkout' => env('WORKFLOWS_KEYS_CHECKOUT', rtrim((string) (env('SCANNER_ROOT') ?: env('HOME').'/Herd'), '/').'/keys.dashcore.com'),

    // This install's name in keys.dashcore.com, which files its secrets
    // under the production hostname whatever environment reads them. Unset
    // means this install's own host (install.host): every fleet's tests has
    // its own check, tests.<slug>.<zone>__WORKFLOWS_HEARTBEAT_URL__local.
    'keys_app' => env('WORKFLOWS_KEYS_APP'),

    // The page warns when the schedule has not ticked for this long.
    'stale_after_minutes' => (int) env('WORKFLOWS_STALE_AFTER_MINUTES', 5),

    // The heartbeat fails when tests:workflows has not finished a tick for
    // this long (a walk takes up to run_timeout; several can queue up).
    'tick_stale_after_minutes' => (int) env('WORKFLOWS_TICK_STALE_AFTER_MINUTES', 30),

];
