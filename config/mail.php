<?php

declare(strict_types=1);

/**
 * Mail Configuration
 *
 * Email transport settings for Pulsar.
 * Environment variables override values defined here.
 *
 * @package Pulsar\Config
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Enable Mail
    |--------------------------------------------------------------------------
    |
    | Override with the MAIL_ENABLED environment variable.
    |
    */
    'enabled' => false,

    /*
    |--------------------------------------------------------------------------
    | Default Driver
    |--------------------------------------------------------------------------
    |
    | The default mail transport driver: "smtp", "ses", "mailgun",
    | "postmark", "sendgrid", "log", or "array".
    | Override with the MAIL_DRIVER environment variable.
    |
    */
    'default_driver' => 'smtp',

    /*
    |--------------------------------------------------------------------------
    | Default From Address
    |--------------------------------------------------------------------------
    |
    | The default sender email address for outgoing mail.
    | Override with the MAIL_FROM_ADDRESS environment variable.
    |
    */
    'default_from_address' => '',

    /*
    |--------------------------------------------------------------------------
    | Default From Name
    |--------------------------------------------------------------------------
    |
    | The default sender display name for outgoing mail.
    | Override with the MAIL_FROM_NAME environment variable.
    |
    */
    'default_from_name' => '',

    /*
    |--------------------------------------------------------------------------
    | Default Reply-To
    |--------------------------------------------------------------------------
    |
    | The default reply-to address for outgoing mail.
    | Override with the MAIL_REPLY_TO environment variable.
    |
    */
    'default_reply_to' => '',

    /*
    |--------------------------------------------------------------------------
    | Encryption Policy
    |--------------------------------------------------------------------------
    |
    | Global TLS policy for outgoing mail: "require", "prefer", or "none".
    | "require" — reject delivery if TLS is unavailable.
    | "prefer"  — use TLS when available, fall back to plaintext.
    | "none"    — no TLS requirement.
    | Override with the MAIL_ENCRYPTION_POLICY environment variable.
    |
    */
    'encryption_policy' => 'none',

    /*
    |--------------------------------------------------------------------------
    | HIPAA Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, outgoing mail content is scrubbed for PHI patterns
    | (SSN, MRN, phone numbers, etc.) before sending.
    | Override with the MAIL_HIPAA_MODE environment variable.
    |
    */
    'hipaa_mode' => false,

    /*
    |--------------------------------------------------------------------------
    | Audit Hash
    |--------------------------------------------------------------------------
    |
    | When enabled, outgoing mail metadata is HMAC-hashed in audit logs
    | for tamper-evident audit trails.
    | Override with the MAIL_AUDIT_HASH environment variable.
    |
    */
    'audit_hash_enabled' => false,

    /*
    |--------------------------------------------------------------------------
    | Driver Options
    |--------------------------------------------------------------------------
    |
    | Driver-specific configuration arrays, keyed by driver name.
    |
    */
    'driver_options' => [
        'smtp' => [
            'host' => 'localhost',
            'port' => 587,
            'username' => null,
            'password' => null,
            'encryption' => 'tls',
            'timeout' => 30,
        ],

        // 'ses' => [
        //     'region' => 'us-east-1',
        //     'access_key' => '',
        //     'secret_key' => '',
        //     'endpoint' => null,
        // ],

        // 'mailgun' => [
        //     'domain' => '',
        //     'api_key' => '',
        //     'endpoint' => 'https://api.mailgun.net',
        // ],

        // 'postmark' => [
        //     'server_token' => '',
        // ],

        // 'sendgrid' => [
        //     'api_key' => '',
        // ],
    ],

    /*
    | Inbound provider webhooks (bounces/complaints). Opt-in: when enabled,
    | Pulsar exposes a single POST endpoint that verifies the provider
    | signature, rejects replays/stale events, deduplicates, and audit-logs
    | bounces and complaints. SES uses certificate verification (no secret);
    | mailgun/postmark/sendgrid use a shared secret.
    */
    'webhooks' => [
        'enabled' => false,
        'provider' => '',        // 'mailgun' | 'postmark' | 'sendgrid' | 'ses'
        'secret' => '',          // provider signing secret/token (not needed for ses)
        'path' => '/_pulsar/mail/webhook',
        'replay_window_seconds' => 300,
        'ip_allowlist' => [],    // optional source IP/CIDR allowlist for the endpoint
    ],
];
