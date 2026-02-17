<?php

declare(strict_types=1);

/**
 * Application Configuration
 *
 * Core application settings for Pulsar.
 * Environment-specific values should use getenv() or a dedicated secrets loader.
 *
 * @package Pulsar\Config
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    */
    'name' => 'Pulsar',

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    | Supported: "local", "staging", "production"
    */
    'env' => 'local',

    /*
    |--------------------------------------------------------------------------
    | Debug Mode
    |--------------------------------------------------------------------------
    | Must be false in production.
    */
    'debug' => false,

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    */
    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale
    |--------------------------------------------------------------------------
    */
    'locale' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Enabled Extensions
    |--------------------------------------------------------------------------
    |
    | When set, only extensions whose names appear in this list will be loaded.
    | If this key is absent or null, all discovered extensions are loaded
    | (backward compatible default). Extension names use the format defined
    | in pulsar.json manifests (e.g., 'pulsar/cms', 'pulsar/forum').
    |
    | Example:
    |   'extensions' => ['enabled' => ['pulsar/cms', 'pulsar/analytics']],
    |
    */
    // 'extensions' => ['enabled' => null],
];
