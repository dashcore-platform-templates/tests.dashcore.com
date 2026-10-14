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

    'step_timeout_ms' => (int) env('WORKFLOWS_STEP_TIMEOUT_MS', 20000),

    'run_timeout' => (int) env('WORKFLOWS_RUN_TIMEOUT', 180),

];
