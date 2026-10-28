<?php

/*
|--------------------------------------------------------------------------
| This install
|--------------------------------------------------------------------------
|
| Every fleet has its own tests site, and every one of them runs on the same
| Mac under Herd: tests.dashcore.com for Dashcore's own fleet, and
| tests.<slug>.<zone> on the tailnet for each client or practice fleet. They
| share one Postgres server, one Xdebug and one pair of hands, so everything
| they make on the host — scratch databases, worktrees, launchd workers, the
| heartbeat — is named after the fleet the install belongs to.
|
| `host` is the site's production name (APP_URL without Herd's .test);
| `fleet` its fleet's slug, by default the label after "tests.". The home
| fleet is Dashcore's own: the only one whose apps are scanned from origin,
| whose PRs are judged and which gets test-building PRs.
|
*/

$host = (string) parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST);
$host = preg_replace('/\.test$/', '', $host) ?: 'localhost';
$labels = explode('.', preg_replace('/^tests\./', '', $host));

return [

    'host' => env('TESTS_HOST', $host),

    'fleet' => env('TESTS_FLEET', $labels[0] === 'localhost' ? 'dashcore' : $labels[0]),

    'home_fleet' => env('TESTS_HOME_FLEET', 'dashcore'),

    // fleets.dashcore.com's checkout: `fleet:export --json` there says which
    // release every app of every fleet runs live (App\Services\FleetExport).
    'fleets_checkout' => env('TESTS_FLEETS_CHECKOUT', rtrim((string) (env('SCANNER_ROOT') ?: env('HOME').'/Herd'), '/').'/fleets.dashcore.com'),

];
