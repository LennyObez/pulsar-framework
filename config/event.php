<?php

declare(strict_types=1);

/**
 * Event System Configuration
 *
 * Controls the event dispatcher, storm protection, and dispatch behavior.
 * Environment variables override values defined here.
 *
 * @package Pulsar\Config
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Enable Event System
    |--------------------------------------------------------------------------
    |
    | Master switch for the event dispatcher. When disabled, no event wiring
    | is registered in the container. Override with EVENT_ENABLED env var.
    |
    */
    'enabled' => true,

    /*
    |--------------------------------------------------------------------------
    | Storm Protection
    |--------------------------------------------------------------------------
    |
    | Guards against runaway event cascades. maxDepth limits total dispatch
    | chain length. Loop detection counts re-entrant dispatches of the same
    | event class within a single chain.
    |
    */
    'storm_protection' => [
        'max_depth' => 32,
        'loop_detection' => true,
        'max_repeats_per_event' => 3,
    ],
];
