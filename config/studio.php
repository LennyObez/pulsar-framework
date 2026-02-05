<?php

declare(strict_types=1);

/**
 * Studio Configuration
 *
 * Pulsar Studio provides real-time observability, debugging, and audit
 * capabilities. In local/dev environments, Studio is enabled by default
 * when this config file exists. In staging/production, explicit environment
 * variables are required.
 *
 * @package Pulsar\Config
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Enable Studio
    |--------------------------------------------------------------------------
    |
    | Override with the STUDIO_ENABLED environment variable.
    | In staging, requires STUDIO_ENABLED=true.
    | In production, requires both STUDIO_ENABLED=true and
    | STUDIO_PRODUCTION_CONFIRM=true.
    |
    */
    'enabled' => true,

    /*
    |--------------------------------------------------------------------------
    | Storage Path
    |--------------------------------------------------------------------------
    |
    | Path to the SQLite database file for event storage.
    | Override with STUDIO_STORAGE_PATH environment variable.
    |
    */
    'storage_path' => 'storage/studio/studio.sqlite',

    /*
    |--------------------------------------------------------------------------
    | Sampling Rate
    |--------------------------------------------------------------------------
    |
    | Fraction of events to record (0.0 to 1.0).
    | Override with STUDIO_SAMPLING_RATE environment variable.
    | Default: 1.0 (100%) in local, recommended 0.1 (10%) in production.
    |
    */
    'sampling_rate' => 1.0,

    /*
    |--------------------------------------------------------------------------
    | Retention Policy
    |--------------------------------------------------------------------------
    |
    | Controls how long events are kept and storage limits.
    | Override max_age_days with STUDIO_RETENTION_DAYS environment variable.
    |
    */
    'retention' => [
        'max_age_days' => 7,
        'max_size_mb' => 500,
        'vacuum_interval_hours' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    |
    | Access control settings for the Studio web interface.
    |
    */
    'security' => [
        'auth_required' => false,
        'username' => null,
        'password' => null,
        'allowed_cidrs' => ['127.0.0.1/8', '::1/128'],
        'production_confirm' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Server
    |--------------------------------------------------------------------------
    |
    | Built-in development server settings.
    | Override with STUDIO_HOST and STUDIO_PORT environment variables.
    |
    */
    'server' => [
        'host' => '127.0.0.1',
        'port' => 8585,
        'document_root' => 'extensions/studio/dev/public',
    ],

    /*
    |--------------------------------------------------------------------------
    | Collectors
    |--------------------------------------------------------------------------
    |
    | Enable/disable individual event collectors and their settings.
    |
    */
    'collectors' => [
        'http' => [
            'enabled' => true,
        ],
        'database' => [
            'enabled' => true,
            // Store original SQL template alongside normalized version.
            // ONLY effective in Local environment. Ignored in staging/production.
            'store_raw_sql' => false,
            // Redact table names with hashed identifiers.
            // Default false in local, recommended true in staging/production.
            'redact_table_names' => false,
        ],
        'logs' => [
            'enabled' => true,
        ],
        'exceptions' => [
            'enabled' => true,
        ],
        'scheduler' => [
            'enabled' => true,
        ],
        'feature_flags' => [
            'enabled' => true,
        ],
        'queue' => [
            'enabled' => true,
        ],
    ],
];
