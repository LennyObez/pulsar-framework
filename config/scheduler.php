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
    | Overlap Prevention
    |--------------------------------------------------------------------------
    |
    | There is no global lock timeout. A job that must not overlap declares it
    | per job, with the lifetime that suits that job's worst-case runtime:
    |
    |     $schedule->job(new ReportJob())
    |         ->daily()
    |         ->withoutOverlapping($lock, expiresAfterMinutes: 120);
    |
    | A `lock_timeout` key here used to be parsed and read by nothing; it is now
    | reported as an unknown key rather than silently accepted.
    |
    */

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
