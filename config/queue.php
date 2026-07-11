<?php

declare(strict_types=1);

/**
 * Queue Configuration
 *
 * Job queue settings for Pulsar.
 * Environment variables override values defined here.
 *
 * @package Pulsar\Config
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Enable Queue
    |--------------------------------------------------------------------------
    |
    | Override with the QUEUE_ENABLED environment variable.
    |
    */
    'enabled' => false,

    /*
    |--------------------------------------------------------------------------
    | Default Driver
    |--------------------------------------------------------------------------
    |
    | The default queue driver to use: "sync", "database", or "memory".
    | Override with the QUEUE_DRIVER environment variable.
    |
    */
    'driver' => 'sync',

    /*
    |--------------------------------------------------------------------------
    | Default Queue Name
    |--------------------------------------------------------------------------
    |
    | The default queue name for dispatched jobs.
    |
    */
    'default_queue' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Worker
    |--------------------------------------------------------------------------
    |
    | Worker loop settings for `queue:work`.
    |
    */
    'worker' => [
        'max_jobs' => 1000,
        'max_memory_mb' => 256,
        'time_limit_seconds' => 3600,
        'sleep_ms' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry
    |--------------------------------------------------------------------------
    |
    | Default retry policy for failed jobs.
    |
    */
    'retry' => [
        'max_attempts' => 3,
        'base_delay_ms' => 1000,
        'max_delay_ms' => 60000,
        'multiplier' => 2.0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Dead-Letter Queue
    |--------------------------------------------------------------------------
    |
    | Settings for jobs that exhaust all retries.
    |
    */
    'dead_letter' => [
        'enabled' => true,
        'retention_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Middleware
    |--------------------------------------------------------------------------
    |
    | Optional middleware for the job pipelines. `encrypt_payloads` encrypts
    | payloads of #[Encrypted]-classified jobs at rest (AEAD, key material from
    | the security master key). `prevent_duplicates` and `rate_limit` require a
    | lock: bind Pulsar\Cache\Application\Lock\LockInterface in the container.
    | Use a lock all workers share -- DatabaseLock or RedisLock from
    | Pulsar\Cache\Application\Lock -- across multiple hosts; FilesystemLock
    | only coordinates workers on a single host. Enabling a middleware whose
    | dependency is missing fails fast at boot.
    |
    */
    'middleware' => [
        'encrypt_payloads' => false,
        'enforce_effect_classification' => false,
        'prevent_duplicates' => [
            'enabled' => false,
            'ttl_seconds' => 300,
        ],
        'rate_limit' => [
            'enabled' => false,
            'ttl_seconds' => 1,
            'timeout_ms' => 0,
        ],
    ],
];
