<?php

declare(strict_types=1);

/**
 * Observability Configuration
 *
 * Configuration for Pulsar's in-house observability suite:
 * logging, metrics, tracing, and error reporting.
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
    | Audit Logging
    |--------------------------------------------------------------------------
    */
    'audit' => [
        'enabled' => true,
        'events' => [
            'authentication',
            'authorization',
            'data_access',
            'configuration_change',
        ],
    ],
];
