<?php

declare(strict_types=1);

/**
 * Security Configuration
 *
 * Security defaults for Pulsar.
 * These settings are designed to be secure by default.
 *
 * @package Pulsar\Config
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Session Security
    |--------------------------------------------------------------------------
    */
    'session' => [
        'cookie_name' => 'PULSAR_SESSION',
        'lifetime' => 7200,
        'cookie_httponly' => true,
        'cookie_secure' => true,
        'cookie_samesite' => 'Strict',
        'regenerate_on_privilege_change' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | CSRF Protection
    |--------------------------------------------------------------------------
    */
    'csrf' => [
        'enabled' => true,
        'token_length' => 32,
        'header_name' => 'X-CSRF-Token',
        'form_field_name' => '_csrf_token',
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Headers
    |--------------------------------------------------------------------------
    */
    'headers' => [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    */
    'rate_limiting' => [
        'enabled' => true,
        'default_limit' => 60,
        'default_window' => 60,
    ],
];
