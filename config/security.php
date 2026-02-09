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
    | Cipher Suite
    |--------------------------------------------------------------------------
    |
    | Determines the cryptographic primitives used for encryption and HMAC.
    | Supported: "sodium" (default, XSalsa20-Poly1305 / BLAKE2b),
    |            "aes-gcm" (AES-256-GCM / HMAC-SHA256, FIPS 140-2 compliant).
    |
    */
    'cipher_suite' => 'sodium',

    /*
    |--------------------------------------------------------------------------
    | Session Security
    |--------------------------------------------------------------------------
    */
    'session' => [
        'handler' => 'file',
        'cookie_name' => 'PULSAR_SESSION',
        'lifetime' => 7200,
        'cookie_httponly' => true,
        'cookie_secure' => true,
        'cookie_samesite' => 'Strict',
        'cookie_path' => '/',
        'cookie_domain' => '',
        'regenerate_on_privilege_change' => true,
        'encryption' => true,
        'max_concurrent_sessions' => 3,
        'save_path' => '',
        'gc_probability' => 1,
        'gc_divisor' => 100,
        'cookie_max_payload_size' => 2048,
        'cookie_replay_window' => 86400,

        'validators' => [
            'user_agent' => [
                'enabled' => true,
                'mode' => 'normalized',
            ],
            'remote_address' => [
                'enabled' => false,
                'mode' => 'subnet',
                'ipv4_mask' => 24,
                'ipv6_mask' => 48,
            ],
            'fingerprint' => [
                'enabled' => false,
                'attributes' => ['accept_language', 'accept_encoding'],
            ],
        ],
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

        // Content Security Policy
        'csp' => [
            'enabled' => true,
            'report_only' => false,
            'default_src' => "'self'",
            'script_src' => "'self'",
            'style_src' => "'self'",
            'object_src' => "'none'",
            'base_uri' => "'self'",
            'frame_ancestors' => "'self'",
        ],

        // HTTP Strict Transport Security
        'hsts' => [
            'enabled' => true,
            'max_age' => 31536000,
            'include_sub_domains' => true,
            'preload' => false,
        ],

        // Cross-Origin headers (COOP, COEP, CORP)
        'cross_origin' => [
            'opener_policy' => 'same-origin',
            'embedder_policy' => '',
            'resource_policy' => 'same-origin',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Key Overrides (per-subsystem key provisioning)
    |--------------------------------------------------------------------------
    |
    | Optional per-context key overrides for independent key rotation.
    | Each entry maps a KDF context string to an environment variable containing
    | hex-encoded raw key bytes. When set, the override key is used instead of
    | deriving from PULSAR_MASTER_KEY for that context.
    |
    | This reduces blast radius: rotating one subsystem key does not affect others.
    |
    */
    'key_overrides' => [
        // 'encrypt_' => env('PULSAR_ENCRYPTION_KEY'),
        // 'audit___' => env('PULSAR_AUDIT_KEY'),
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
