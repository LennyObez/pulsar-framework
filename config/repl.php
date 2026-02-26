<?php

declare(strict_types=1);

/**
 * REPL Configuration
 *
 * Interactive shell settings for Pulsar.
 * Environment variables override values defined here.
 *
 * @package Pulsar\Config
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Enable REPL
    |--------------------------------------------------------------------------
    |
    | Whether the interactive REPL shell is available. Disabled by default for
    | security. Override with the REPL_ENABLED environment variable.
    |
    */
    'enabled' => false,

    /*
    |--------------------------------------------------------------------------
    | Safe Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, the REPL wraps database connections, queue drivers, storage
    | adapters, and cache backends with read-only decorators that block mutations.
    |
    */
    'safe_mode' => true,

    /*
    |--------------------------------------------------------------------------
    | Audit Logging
    |--------------------------------------------------------------------------
    |
    | When enabled, REPL session lifecycle events are logged via the audit logger:
    | session start/end and production overrides.
    |
    */
    'audit' => false,

    /*
    |--------------------------------------------------------------------------
    | History File
    |--------------------------------------------------------------------------
    |
    | Path to the REPL command history file, relative to the project root.
    |
    */
    'history_file' => '.pulsar_repl_history',

    /*
    |--------------------------------------------------------------------------
    | Startup Commands
    |--------------------------------------------------------------------------
    |
    | PHP statements to execute when the REPL session starts. Useful for
    | importing frequently used namespaces or setting up helper variables.
    |
    */
    'startup_commands' => [],
];
