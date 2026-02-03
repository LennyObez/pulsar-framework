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
    ],
];
