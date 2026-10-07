<?php

declare(strict_types=1);

/**
 * Resilience Configuration
 *
 * Retry policies, circuit breakers, and health check settings for Pulsar.
 * Environment variables override values defined here.
 *
 * @package Pulsar\Config
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Enable Resilience Features
    |--------------------------------------------------------------------------
    |
    | Override with the RESILIENCE_ENABLED environment variable.
    |
    */
    'enabled' => false,

    /*
    |--------------------------------------------------------------------------
    | Retry Policy Defaults
    |--------------------------------------------------------------------------
    |
    | Default settings for retry policies.
    |
    */
    'retry' => [
        'max_attempts' => 3,
        'base_delay_ms' => 100,
        'max_delay_ms' => 5000,
        'multiplier' => 2.0,
        'jitter' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker Defaults
    |--------------------------------------------------------------------------
    |
    | Default settings for circuit breakers.
    |
    */
    'circuit_breaker' => [
        'failure_threshold' => 5,
        'success_threshold' => 2,
        'open_timeout_seconds' => 30,
        'sample_window_seconds' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Health Check Defaults
    |--------------------------------------------------------------------------
    |
    | Default settings for health checks.
    |
    */
    'health_check' => [
        'interval_seconds' => 30,
        'timeout_seconds' => 5,

        // FIPS 140-2 compliance check on /health. Enable ONLY on deployments
        // that must run OpenSSL in FIPS mode: on any other host the check
        // reports "degraded" and /health answers 503, which ejects the
        // instance from its load-balancer pool (intended fail-closed
        // behaviour for FIPS-regulated environments).
        'fips_check' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sealed Backup and Restore
    |--------------------------------------------------------------------------
    |
    | The framework backup primitive. Unlike everything above it, this defaults
    | to ON: no deployment can assert that recovery does not apply to it, which
    | is why NIST CSF RC.RP, SOC 2 A1.3 and HIPAA 164.308(a)(7) cannot be scoped
    | out, and a primitive that shipped off would leave every default install in
    | the gap those controls name.
    |
    | Being on binds the service; it does not take a backup. Scheduling, offsite
    | replication and retention stay with the operator -- see docs/backup.md and
    | Pulsar\Resilience\Backup\BackupPlan::NOT_COVERED, which state the boundary
    | in prose and in code respectively.
    |
    | It fails closed. Without PULSAR_MASTER_KEY there is no archive key, and the
    | wiring binds nothing rather than writing an unsealed archive.
    |
    | The audit trail is NOT listed here. Its directory is derived from
    | observability.audit.log_path, so moving the audit log moves its backup with
    | it and the two cannot drift apart.
    |
    | Override with BACKUP_ENABLED and BACKUP_DESTINATION.
    |
    */
    'backup' => [
        'enabled' => true,

        // Archives land here. Refused at boot if it resolves inside public/.
        'destination' => 'storage/backups',

        // File trees to archive, as 'entry id' => path. The id becomes the first
        // path segment of every entry the tree contributes, so a restore can be
        // routed per tree. Set to [] on a deployment whose files live in object
        // storage; that is honoured rather than replaced by the default.
        'trees' => [
            'files' => 'storage/app',
        ],
    ],
];
