<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Permission Map
    |--------------------------------------------------------------------------
    |
    | Maps permission keys to role access levels. Each key is a page or feature
    | identifier. Each role can have one of three access levels:
    |
    |   'full' - Full access (read + write)
    |   'view' - Read-only access
    |   null/missing - No access (403)
    |
    | Any key not listed here defaults to Owner + Admin only (default-deny).
    |
    */

    // Built-in pages
    'dashboard' => [
        'owner' => 'full',
        'admin' => 'full',
        'finance' => 'full',
        'member' => 'full',
    ],

    'profile' => [
        'owner' => 'full',
        'admin' => 'full',
        'finance' => 'full',
        'member' => 'full',
    ],

    'team-management' => [
        'owner' => 'full',
        'admin' => 'full',
    ],

    'company-settings' => [
        'owner' => 'full',
        'admin' => 'full',
    ],

    'permissions' => [
        'owner' => 'full',
        'admin' => 'full',
    ],

    'billing' => [
        'owner' => 'full',
        'admin' => 'view',
        'finance' => 'full',
    ],

    'activity-log' => [
        'owner' => 'full',
        'admin' => 'view',
    ],

    'integrations' => [
        'owner' => 'full',
        'admin' => 'full',
    ],

    // Business page defaults
    'operational' => [
        'owner' => 'full',
        'admin' => 'full',
        'finance' => 'view',
        'member' => 'full',
    ],

    'finance' => [
        'owner' => 'full',
        'finance' => 'full',
    ],

    'reports' => [
        'owner' => 'full',
        'admin' => 'full',
        'finance' => 'full',
        'member' => 'view',
    ],

    // Fleet test coverage: everyone can read it, owners and admins can
    // trigger scans and re-read the fleet roster.
    'fleet-tests' => [
        'owner' => 'full',
        'admin' => 'full',
        'finance' => 'view',
        'member' => 'view',
    ],

    /*
    |--------------------------------------------------------------------------
    | Modules
    |--------------------------------------------------------------------------
    |
    | UI-only metadata that groups permission keys into logical modules for
    | display on the admin permissions page. Each entry is a module label
    | mapped to an array of permission keys. Permission resolution ignores
    | this section — accidentally using "modules" as a permission key falls
    | back to default-deny (owner + admin only).
    |
    */

    'modules' => [
        'General' => [
            'dashboard',
            'profile',
        ],
        'Tests' => [
            'fleet-tests',
        ],
        'Settings' => [
            'team-management',
            'company-settings',
            'permissions',
            'integrations',
        ],
        'Finance' => [
            'billing',
            'finance',
        ],
        'Business' => [
            'operational',
            'reports',
            'activity-log',
        ],
    ],

];
