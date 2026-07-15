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

        // Compliance log sink: masks/pseudonymizes every log entry per the
        // selected regulations (GDPR/HIPAA pseudonymization needs the security
        // master key) and writes to a dedicated durable file, encrypted at
        // rest when the security encryptor is available. Empty 'frameworks'
        // applies all of: gdpr, hipaa, pci-dss, sox.
        //
        // IMPORTANT -- this is an ADDITIONAL, masked copy. The regular channels
        // above still receive the ORIGINAL, unmasked entries. If raw PII must
        // not persist on disk, point the regular channels at a stream (stderr)
        // or apply your retention policy to their files; the compliance file
        // is the durable, masked artifact meant for long-term retention.
        'compliance' => [
            'enabled' => false,
            'frameworks' => [],
            'path' => 'var/logs/compliance.log',
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
            'openmetrics' => [
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
