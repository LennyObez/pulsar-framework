<?php

declare(strict_types=1);

/**
 * Unified authentication configuration.
 *
 * Consolidates social SSO, OAuth2 server, and WebAuthn settings
 * under a single configuration namespace.
 *
 * @see \Pulsar\Extension\Auth\Config\AuthConfig
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Social SSO Configuration
    |--------------------------------------------------------------------------
    |
    | enabled: Master toggle for the SSO extension.
    | default_provider: Default OAuth provider name.
    | require_pkce: Enforce PKCE (S256-only) for all providers.
    | require_nonce: Enforce nonce verification for OIDC providers.
    | state_ttl_seconds: Lifetime of OAuth state tokens.
    |
    */

    'social' => [
        'enabled' => false,
        'default_provider' => '',
        'require_pkce' => true,
        'require_nonce' => true,
        'state_ttl_seconds' => 300,

        'routes' => [
            'login_path' => '/sso/{provider}/login',
            'callback_path' => '/sso/{provider}/callback',
        ],

        'providers' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | OAuth2 Authorization Server Configuration
    |--------------------------------------------------------------------------
    */

    'oauth2' => [
        'issuer' => getenv('OAUTH2_ISSUER') ?: '',
        'access_token_ttl' => 900,
        'refresh_token_ttl' => 2_592_000,
        'authorization_code_ttl' => 600,
        'dynamic_registration' => false,
        'signing_algorithms' => ['RS256', 'ES256'],
        'pairwise_subjects' => false,
        'signing_key_id' => 'oauth_sign',
        'token_format' => 'reference',
    ],

    /*
    |--------------------------------------------------------------------------
    | WebAuthn/FIDO2 Configuration
    |--------------------------------------------------------------------------
    */

    'webauthn' => [
        'rp_name' => getenv('WEBAUTHN_RP_NAME') ?: 'Pulsar Application',
        'rp_id' => getenv('WEBAUTHN_RP_ID') ?: 'localhost',
        'origin' => getenv('WEBAUTHN_ORIGIN') ?: 'https://localhost',
        'user_verification' => 'preferred',
        'attestation' => 'none',
        'allowed_formats' => ['none', 'packed'],
        'challenge_ttl_seconds' => 300,
        'timeout' => 60000,
    ],
];
