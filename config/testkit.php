<?php

/*
|--------------------------------------------------------------------------
| DashCore testkit
|--------------------------------------------------------------------------
|
| Each check is a ratchet. `php artisan testkit:install` (or
| `testkit:baseline`) records what the app has today in the `allow` lists
| below, so the baseline test passes on day one and fails only on something
| new. Entries are debt to work down, not a place to put new exceptions
| without a reason.
|
*/

return [

    /*
    | Routes. Every app route must carry one of `guards` — or be listed in
    | `public`, which is the deliberate decision that anyone may reach it.
    | Patterns match "METHOD uri" with * as a wildcard.
    */
    'routes' => [
        'guards' => ['auth', 'auth:*', 'Illuminate\Auth\Middleware\Authenticate', 'Illuminate\Auth\Middleware\Authenticate:*', 'signed', 'Illuminate\Routing\Middleware\ValidateSignature', 'Illuminate\Routing\Middleware\ValidateSignature:*', 'bridge.auth', 'guest', 'Illuminate\Auth\Middleware\RedirectIfAuthenticated'],
        'public' => [
            'GET invitations/{token}',
            'GET /',
        ],
    ],

    /*
    | The database the suite must run on. The fleet runs Postgres in
    | production, so the tests do too. Null skips the check.
    */
    'database' => [
        'driver' => 'pgsql',
    ],

    /*
    | Calls that must not ship in app code. `allow` holds "path:line" entries.
    */
    'debug_calls' => [
        'functions' => ['dd', 'dump', 'ddd', 'ray', 'var_dump', 'print_r', 'dumps'],
        'allow' => [],
    ],

    /*
    | env() outside config/ returns null once config is cached in production.
    */
    'env_calls' => [
        'allow' => [],
    ],

    /*
    | DashCore rule: every Livewire action gives feedback while it runs. An
    | action is wire:click / wire:submit / wire:change; feedback is wire:loading
    | aimed at it (or untargeted) in the same view, or wire:loading.attr on the
    | element itself. `allow` holds "view-path@method" entries.
    */
    'loading' => [
        'allow' => [
            'resources/views/livewire/layout/navigation.blade.php@logout',
            'resources/views/livewire/pages/auth/confirm-password.blade.php@confirmPassword',
            'resources/views/livewire/pages/auth/forgot-password.blade.php@sendPasswordResetLink',
            'resources/views/livewire/pages/auth/register.blade.php@register',
            'resources/views/livewire/pages/auth/reset-password.blade.php@resetPassword',
            'resources/views/livewire/pages/auth/verify-email.blade.php@logout',
            'resources/views/livewire/pages/auth/verify-email.blade.php@sendVerification',
            'resources/views/livewire/profile/delete-user-form.blade.php@deleteUser',
            'resources/views/livewire/profile/update-password-form.blade.php@updatePassword',
            'resources/views/livewire/profile/update-profile-information-form.blade.php@sendVerification',
            'resources/views/livewire/profile/update-profile-information-form.blade.php@updateProfileInformation',
            'resources/views/livewire/team/index.blade.php@closeAddUserModal',
            'resources/views/livewire/team/index.blade.php@closeInviteModal',
            'resources/views/livewire/team/index.blade.php@openAddUserModal',
            'resources/views/livewire/team/index.blade.php@openInviteModal',
        ],
    ],

];
