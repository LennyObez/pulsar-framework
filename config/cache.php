<?php

declare(strict_types=1);

/**
 * Application Cache Configuration
 *
 * PSR-6 / PSR-16 cache settings for Pulsar.
 * Environment variables override values defined here.
 *
 * @package Pulsar\Config
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Enable Application Cache
    |--------------------------------------------------------------------------
    |
    | Override with the CACHE_ENABLED environment variable.
    |
    */
    'enabled' => false,

    /*
    |--------------------------------------------------------------------------
    | Default Pool
    |--------------------------------------------------------------------------
    |
    | The default cache pool used by PSR-6 and PSR-16 bindings.
    | Override with the CACHE_DEFAULT_POOL environment variable.
    |
    */
    'default_pool' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Path
    |--------------------------------------------------------------------------
    |
    | Base directory for filesystem cache driver.
    | Override with the CACHE_PATH environment variable.
    |
    */
    'path' => 'var/cache',

    /*
    |--------------------------------------------------------------------------
    | Cache Pools
    |--------------------------------------------------------------------------
    |
    | Named pool configurations. Each pool can use a different driver,
    | serializer, TTL, and feature set.
    |
    | Available drivers: "array", "filesystem", "database", "redis",
    |                    "memcached", "apcu"
    |
    | Available serializers: "json" (default, safe), "php" (opt-in)
    |
    */
    'pools' => [
        'default' => [
            'driver' => 'filesystem',
            'serializer' => 'json',
            'default_ttl_seconds' => null,
            'critical' => false,
            'encrypted' => false,
            'tags_strategy' => 'auto',
        ],

        // 'redis' => [
        //     'driver' => 'redis',
        //     'host' => '127.0.0.1',
        //     'port' => 6379,
        //     'serializer' => 'json',
        //     'default_ttl_seconds' => 3600,
        //     'tags_strategy' => 'auto',
        // ],

        // 'database' => [
        //     'driver' => 'database',
        //     'serializer' => 'json',
        //     'default_ttl_seconds' => 86400,
        //     'encrypted' => true,
        //     'critical' => true,
        // ],
    ],
];
