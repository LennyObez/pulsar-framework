<?php

declare(strict_types=1);

/**
 * Observability Configuration
 *
 * Configuration for Pulsar's in-house observability suite:
 * logging, metrics, tracing, error tracking, and audit logging.
 *
 * @package Pulsar\Config
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'default_channel' => 'file',
        'level' => 'info',
        'channels' => [
            'file' => [
                'driver' => 'file',
                'path' => 'var/logs/pulsar.log',
            ],
            'stderr' => [
                'driver' => 'stream',
                'stream' => 'php://stderr',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    */
    'metrics' => [
        'enabled' => true,
        'exporters' => [
            'prometheus' => [
                'enabled' => false,
                'endpoint' => '/metrics',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tracing
    |--------------------------------------------------------------------------
    */
    'tracing' => [
        'enabled' => false,
        'sampling_rate' => 0.1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Error Tracking
    |--------------------------------------------------------------------------
    */
    'error_tracking' => [
        'enabled' => true,
        'max_groups' => 500,
        'max_recent_events_per_group' => 5,
        'sensitive_fields' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Logging
    |--------------------------------------------------------------------------
    */
    'audit' => [
        'enabled' => true,
        'log_path' => 'var/logs/audit.jsonl',
        'events' => [
            'authentication',
            'authorization',
            'data_access',
            'configuration_change',
        ],
    ],
];
