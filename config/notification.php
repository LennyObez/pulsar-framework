<?php

declare(strict_types=1);

/**
 * Notification Configuration
 *
 * Multi-channel notification settings for Pulsar.
 * Environment variables override values defined here.
 *
 * @package Pulsar\Config
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Enable Notifications
    |--------------------------------------------------------------------------
    |
    | Override with the NOTIFICATION_ENABLED environment variable.
    |
    */
    'enabled' => false,

    /*
    |--------------------------------------------------------------------------
    | Default Channels
    |--------------------------------------------------------------------------
    |
    | Channels used when a notification does not specify via().
    | Available: "mail", "sms", "database", "slack", "webhook", "log".
    |
    */
    'default_channels' => [],

    /*
    |--------------------------------------------------------------------------
    | Rate Limit
    |--------------------------------------------------------------------------
    |
    | Maximum notifications per minute per notifiable.
    | Override with the NOTIFICATION_RATE_LIMIT environment variable.
    |
    */
    'rate_limit_per_minute' => 60,

    /*
    |--------------------------------------------------------------------------
    | Regulated Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, all notification types must have a legal basis mapping
    | registered at boot. Marketing notifications require explicit opt-in.
    | Override with the NOTIFICATION_REGULATED environment variable.
    |
    */
    'regulated' => false,

    /*
    |--------------------------------------------------------------------------
    | Audit Hash
    |--------------------------------------------------------------------------
    |
    | When enabled, notification metadata is HMAC-hashed in audit logs.
    | Override with the NOTIFICATION_AUDIT_HASH environment variable.
    |
    */
    'audit_hash_enabled' => false,

    /*
    |--------------------------------------------------------------------------
    | Unsubscribe URL Pattern
    |--------------------------------------------------------------------------
    |
    | URL pattern for RFC 8058 List-Unsubscribe headers on marketing emails.
    | Placeholders: {notifiable_id}, {channel}.
    | Override with the NOTIFICATION_UNSUBSCRIBE_URL environment variable.
    |
    */
    'unsubscribe_url_pattern' => null,
];
