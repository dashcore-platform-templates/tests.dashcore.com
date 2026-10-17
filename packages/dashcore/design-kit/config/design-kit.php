<?php

/*
|--------------------------------------------------------------------------
| Design kit
|--------------------------------------------------------------------------
|
| Where `php artisan design:apply <slug>` pulls a design from when it is
| given a slug rather than a URL or a local file. Point it at a local
| design.dashcore.com while developing the standard itself.
|
*/

return [
    'source' => env('DESIGN_SOURCE', 'https://design.dashcore.com'),

    /*
    |--------------------------------------------------------------------------
    | Fleet reporting
    |--------------------------------------------------------------------------
    |
    | This app's own token from design.dashcore.com, which `design:audit
    | --report` authenticates with. Belongs in CI rather than in a repo: it
    | identifies this site and nothing else, but it is still a credential.
    | Without it --report says so and the audit carries on regardless.
    |
    */

    'token' => env('DESIGN_TOKEN'),
];
