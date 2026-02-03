<?php

declare(strict_types=1);

/**
 * Supervisor Configuration
 *
 * Self-healing supervisor settings for Pulsar workers.
 * Environment variables override values defined here.
 *
 * @package Pulsar\Config
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Enable Supervisor
    |--------------------------------------------------------------------------
    |
    | Override with the SUPERVISOR_ENABLED environment variable.
    |
    */
    'enabled' => false,

    /*
    |--------------------------------------------------------------------------
    | Worker Recycle Policy
    |--------------------------------------------------------------------------
    |
    | Conditions under which a worker should be gracefully recycled.
    |
    */
    'recycle' => [
        'max_requests' => 10000,
        'memory_threshold_mb' => 256,
        'time_limit_seconds' => 7200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Stuck Job Policy
    |--------------------------------------------------------------------------
    |
    | Detection and recovery settings for stuck jobs.
    |
    */
    'stuck_job' => [
        'timeout_seconds' => 300,
        'check_interval_seconds' => 60,
        'move_to_dead_letter' => true,
    ],
];
