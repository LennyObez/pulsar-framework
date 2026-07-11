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
    |            "aes-gcm" (AES-256-GCM / HMAC-SHA256, FIPS 140-2 compatible).
    |
    | FIPS 140-2 compatible — uses FIPS 140-2 approved algorithms. Achieves
    | full FIPS 140-2 compliance when deployed with a NIST-validated OpenSSL
    | FIPS provider. Use FipsValidator::verify() to confirm compliance.
    |
    */
    'cipher_suite' => 'sodium',

    /*
    |--------------------------------------------------------------------------
    | Session Security
    |--------------------------------------------------------------------------
    |
    | F1.4: `cookie_secure` defaults to `true` (the secure-by-default
    | choice for production), but is environment-aware:
    |   - `SESSION_COOKIE_SECURE` env var, when set, wins explicitly
    |     (accepts "true"/"false"/"1"/"0").
    |   - Otherwise, when `APP_ENV` is `local` or `development`, the
    |     default flips to `false` so a developer running on plain
    |     HTTP is not locked out of the session cookie.
    |   - Otherwise, defaults to `true` (production / staging /
    |     unspecified env all stay secure).
    |
    */
    'session' => [
        'handler' => 'file',
        'cookie_name' => 'PULSAR_SESSION',
        'lifetime' => 7200,
        'cookie_httponly' => true,
        'cookie_secure' => env(
            'SESSION_COOKIE_SECURE',
            in_array(env('APP_ENV', 'production'), ['local', 'development'], true) ? false : true,
        ),
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
        'idle_timeout' => 900, // 15 minutes per PCI-DSS 8.2.8

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
    |
    | Literal `Name => value` entries are emitted verbatim — "what you write is
    | what's emitted". A literal `Strict-Transport-Security` or `Permissions-Policy`
    | takes precedence over the structured `hsts` / `permissions_policy` blocks
    | below; the framework logs a one-time boot warning when a literal shadows an
    | active structured block so the override is never silent. (A literal
    | `Strict-Transport-Security` is still emitted only over HTTPS — RFC 6797 §7.2.)
    |
    | Prefer the structured blocks (`csp`, `hsts`, `cross_origin`, `permissions_policy`,
    | `nel`): they are typed and validated. Note the HSTS key is spelled
    | `include_sub_domains` (with underscores).
    |
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
            'max_age' => 63072000,
            'include_sub_domains' => true,
            'preload' => false,
        ],

        // Cross-Origin headers (COOP, COEP, CORP)
        'cross_origin' => [
            'opener_policy' => 'same-origin',
            'embedder_policy' => '',
            'resource_policy' => 'same-origin',
        ],

        // Network Error Logging (NEL)
        'nel' => [
            'enabled' => false,
            'report_to' => 'default',
            'max_age' => 86400,
            'include_subdomains' => false,
            'success_fraction' => 0.0,
            'failure_fraction' => 1.0,
        ],
        'nel_endpoint_url' => '',
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
    | Tokenization (PCI-DSS Req 3.4)
    |--------------------------------------------------------------------------
    |
    | Controls the tokenization subsystem used to render sensitive data
    | (such as PANs) unreadable in storage.
    |
    | Supported stores: "memory" (dev/testing), "database" (production).
    | When using "database", ensure the token_vault table exists.
    |
    */
    'tokenization' => [
        'store' => 'memory',
        'table' => 'token_vault',
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

    /*
    |--------------------------------------------------------------------------
    | Web Application Firewall (WAF)
    |--------------------------------------------------------------------------
    |
    | OWASP Core Rule Set (CRS) compatible rules engine.
    | Protects against SQLi, XSS, path traversal, command injection, and more.
    |
    | Paranoia levels (1-4, matching OWASP CRS convention):
    |   1 — Core rules only (recommended for most applications)
    |   2 — Additional rules with moderate false-positive risk
    |   3 — Strict rules for high-security environments
    |   4 — Paranoid mode (may require tuning to avoid false positives)
    |
    */
    'waf' => [
        'enabled' => false,
        'paranoia_level' => 1,
        'bypass_ips' => [],
        'custom_rules_path' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Threat Detection
    |--------------------------------------------------------------------------
    |
    | Active, request-time threat detection. Brute-force, credential-stuffing,
    | API-abuse, and injection-attempt detectors feed a single engine; the
    | middleware blocks or challenges on the highest-severity hit and records a
    | compliance-grade incident. Opt-in (disabled by default) so it never blocks
    | legitimate traffic unexpectedly — enable and tune the thresholds per app.
    |
    */
    'threat_detection' => [
        'enabled' => false,
        'brute_force_threshold' => 5,
        'brute_force_window_seconds' => 600,
        'stuffing_threshold' => 10,
        'stuffing_window_seconds' => 300,
        'api_abuse_threshold' => 100,
        'api_abuse_window_seconds' => 60,
        'geo_travel_speed_kmh' => 900,
        'injection_detection_enabled' => true,

        // Honeypot paths: fake attacker-only routes (wp-login.php, .env, ...).
        // Zero false positives -- these paths are never valid in a Pulsar app.
        // Empty 'paths' uses the built-in list; block_ip=false answers with a
        // fake 404 instead of a 403.
        'honeypot' => [
            'enabled' => false,
            'paths' => [],
            'block_ip' => true,
        ],
    ],
];
