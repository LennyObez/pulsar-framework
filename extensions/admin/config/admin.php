<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Enable Admin Panel
    |--------------------------------------------------------------------------
    |
    | Enabled by default in local/development. In staging/production,
    | explicit environment variables are required for safety.
    | Override with ADMIN_ENABLED environment variable.
    |
    */
    'enabled' => true,

    'route_prefix' => '/admin',

    'security' => [
        'required_role' => 'admin',
        'require_2fa' => true,
        'csrf_rotation' => true,
        'csp_nonce' => true,
    ],

    'pagination' => [
        'default_per_page' => 25,
        'max_per_page' => 100,
    ],

    'rate_limit' => [
        'read_limit' => 120,
        'write_limit' => 30,
        'export_limit' => 5,
        'window_seconds' => 60,
    ],

    'storage' => [
        'driver' => 'sqlite',
        'sqlite_path' => null,
    ],
];
