<?php

declare(strict_types=1);

/**
 * Scheduler Configuration
 *
 * Job scheduler settings for Pulsar.
 * Environment variables override values defined here.
 *
 * @package Pulsar\Config
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Enable Scheduler
    |--------------------------------------------------------------------------
    |
    | Override with the SCHEDULER_ENABLED environment variable.
    |
    */
    'enabled' => false,

    /*
    |--------------------------------------------------------------------------
    | Timezone
    |--------------------------------------------------------------------------
    |
    | Timezone used for evaluating cron schedules.
    |
    */
    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Max Execution Time
    |--------------------------------------------------------------------------
    |
    | Maximum allowed execution time per job in seconds.
    |
    */
    'max_execution_time' => 3600,

    /*
    |--------------------------------------------------------------------------
    | Lock Timeout
    |--------------------------------------------------------------------------
    |
    | Seconds to hold a job lock to prevent overlapping execution.
    |
    */
    'lock_timeout' => 300,

    /*
    |--------------------------------------------------------------------------
    | Log Output
    |--------------------------------------------------------------------------
    |
    | Whether to log job output to the application logger.
    |
    */
    'log_output' => true,
];
