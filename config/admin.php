<?php

declare(strict_types=1);

/**
 * Admin Panel Configuration
 *
 * Pulsar Admin provides CRUD resource management, audit-logged operations,
 * export with evidence hashing, and dashboard widgets. Enabled by default
 * in local/dev environments. In staging/production, explicit environment
 * variables are required.
 *
 * @package Pulsar\Config
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Enable Admin Panel
    |--------------------------------------------------------------------------
    |
    | Override with the ADMIN_ENABLED environment variable.
    | In staging, requires ADMIN_ENABLED=true.
    | In production, requires both ADMIN_ENABLED=true and
    | ADMIN_PRODUCTION_CONFIRM=true.
    |
    */
    'enabled' => true,

    /*
    |--------------------------------------------------------------------------
    | Route Prefix
    |--------------------------------------------------------------------------
    |
    | URL prefix for all admin panel routes.
    |
    */
    'route_prefix' => '/admin',

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    |
    | Access control settings for the admin panel.
    |
    */
    'security' => [
        'required_role' => 'admin',
        'require_2fa' => true,
        'csrf_rotation' => true,
        'csp_nonce' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */
    'pagination' => [
        'default_per_page' => 25,
        'max_per_page' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    */
    'rate_limit' => [
        'read_limit' => 120,
        'write_limit' => 30,
        'export_limit' => 5,
        'window_seconds' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Admin panel internal storage for saved views and action history.
    | 'sqlite' for local development, 'database' for production.
    |
    */
    'storage' => [
        'driver' => 'sqlite',
        'sqlite_path' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Schema Builder
    |--------------------------------------------------------------------------
    |
    | Visual schema management for creating, inspecting, and modifying
    | database tables. Disabled by default for security — enable explicitly.
    |
    */
    'schema' => [
        'enabled' => true,
        'allowed_operations' => ['create', 'alter', 'drop', 'rename'],
        'deny_table_prefixes' => ['admin_', 'pulsar_', 'sqlite_', 'studio_'],
        'require_step_up_for' => ['drop', 'rename', 'drop_column', 'drop_index'],
    ],
];
