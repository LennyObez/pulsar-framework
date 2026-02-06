<?php

declare(strict_types=1);

/**
 * Integrity Configuration
 *
 * File integrity and tamper detection settings for Pulsar.
 * Environment variables override values defined here.
 *
 * @package Pulsar\Config
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Enable Integrity Verification
    |--------------------------------------------------------------------------
    |
    | Override with the INTEGRITY_ENABLED environment variable.
    |
    */
    'enabled' => false,

    /*
    |--------------------------------------------------------------------------
    | Manifest Path
    |--------------------------------------------------------------------------
    |
    | Path to store the integrity manifest file.
    |
    */
    'manifest_path' => 'var/integrity/manifest.json',

    /*
    |--------------------------------------------------------------------------
    | Policy Mode
    |--------------------------------------------------------------------------
    |
    | How to respond to integrity violations per environment.
    | Modes: "warn" (log + continue), "strict" (log + fail deploy check).
    |
    */
    'mode' => 'warn',

    /*
    |--------------------------------------------------------------------------
    | Include Paths
    |--------------------------------------------------------------------------
    |
    | Glob patterns for files to include in integrity verification.
    |
    */
    'include' => [
        'src/**/*.php',
        'config/**/*.php',
        'bin/*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Exclude Paths
    |--------------------------------------------------------------------------
    |
    | Glob patterns for files to exclude from integrity verification.
    |
    */
    'exclude' => [
        'vendor/**',
        'var/**',
        'node_modules/**',
        '.git/**',
    ],
];
