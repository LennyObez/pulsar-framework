<?php

declare(strict_types=1);

/**
 * API Tooling Configuration
 *
 * Controls API resource serialization, pagination, versioning,
 * and complexity limits.
 *
 * @package Pulsar\Config
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Default Response Format
    |--------------------------------------------------------------------------
    |
    | Supported: "json", "jsonapi", "hal"
    |
    */
    'default_format' => 'json',

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */
    'pagination' => [
        'type' => 'offset',        // offset, cursor, keyset
        'default_size' => 25,
        'max_size' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Versioning Strategy
    |--------------------------------------------------------------------------
    |
    | Supported: "url" (/v1/...), "header" (Accept-Version), "query" (?version=1)
    |
    */
    'versioning_strategy' => 'url',

    /*
    |--------------------------------------------------------------------------
    | Complexity Limits
    |--------------------------------------------------------------------------
    |
    | Caps on API request complexity. Exceeded limits produce 400 errors.
    |
    */
    'complexity_limits' => [
        'max_fields' => 50,
        'max_nesting_depth' => 3,
        'max_includes' => 10,
    ],
];
