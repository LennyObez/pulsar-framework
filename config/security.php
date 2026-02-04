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

    /*
    |--------------------------------------------------------------------------
    | Authentication & Authorization
    |--------------------------------------------------------------------------
    */
    'auth' => [
        'default_guard' => 'session',

        'guards' => [
            ['name' => 'session', 'driver' => 'session', 'enabled' => true],
            ['name' => 'token', 'driver' => 'token', 'enabled' => true],
        ],

        'two_factor' => [
            'enabled' => false,
            'issuer' => 'Pulsar',
            'code_digits' => 6,
            'code_period' => 30,
            'verification_window' => 1,
            'recovery_code_count' => 8,
            // Bytes of entropy per recovery code (8 = 64-bit, 4 = 32-bit legacy)
            'recovery_code_bytes' => 8,
            // Minutes before step-up authentication expires
            'step_up_timeout_minutes' => 15,
            // Recovery code algorithm version (2 = 64-bit hashed, 1 = 32-bit plaintext legacy)
            'recovery_code_algorithm_version' => 2,
            // Set to true to suppress warnings about in-memory stores in production
            'allow_in_memory' => false,
        ],

        'authorization' => [
            'roles' => [
                'admin' => [
                    'permissions' => ['*'],
                ],
                'editor' => [
                    'permissions' => ['content.view', 'content.create', 'content.edit'],
                ],
                'viewer' => [
                    'permissions' => ['content.view'],
                ],
            ],
            'super_roles' => ['admin'],
        ],
    ],
];
