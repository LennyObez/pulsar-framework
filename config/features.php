<?php

declare(strict_types=1);

/**
 * Feature Flags Configuration
 *
 * Feature flag definitions and storage settings for Pulsar.
 * Environment variables override values defined here.
 *
 * @package Pulsar\Config
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Enable Feature Flags
    |--------------------------------------------------------------------------
    |
    | Override with the FEATURE_FLAGS_ENABLED environment variable.
    |
    */
    'enabled' => false,

    /*
    |--------------------------------------------------------------------------
    | Storage Driver
    |--------------------------------------------------------------------------
    |
    | Where feature flag definitions are stored.
    | Options: 'memory', 'file'
    |
    */
    'storage' => 'memory',

    /*
    |--------------------------------------------------------------------------
    | File Path
    |--------------------------------------------------------------------------
    |
    | Path to the JSON file when storage is 'file'.
    |
    */
    'file_path' => 'var/flags/flags.json',

    /*
    |--------------------------------------------------------------------------
    | Audit Evaluations
    |--------------------------------------------------------------------------
    |
    | When true, every flag evaluation is recorded in the audit log.
    |
    */
    'audit_evaluations' => false,

    /*
    |--------------------------------------------------------------------------
    | Default State
    |--------------------------------------------------------------------------
    |
    | Default evaluation result when a flag is not found.
    |
    */
    'default_state' => false,

    /*
    |--------------------------------------------------------------------------
    | Flag Definitions
    |--------------------------------------------------------------------------
    |
    | Pre-configured feature flags loaded at boot time.
    |
    */
    'flags' => [
        // 'dark-mode' => [
        //     'enabled' => true,
        //     'type' => 'boolean',
        //     'description' => 'Enable dark mode UI',
        // ],
        // 'new-checkout' => [
        //     'enabled' => true,
        //     'type' => 'percentage',
        //     'percentage' => 25,
        //     'description' => 'Gradual rollout of new checkout flow',
        // ],
    ],
];
