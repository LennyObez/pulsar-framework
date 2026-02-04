<?php

declare(strict_types=1);

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

    'enabled' => false,
    'default_provider' => '',
    'require_pkce' => true,
    'require_nonce' => true,
    'state_ttl_seconds' => 300,

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    */

    'routes' => [
        'login_path' => '/sso/{provider}/login',
        'callback_path' => '/sso/{provider}/callback',
    ],

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Each provider entry defines the OAuth/OIDC configuration.
    | Supported types: 'oidc' (OpenID Connect), 'oauth2' (pure OAuth 2.0).
    |
    | Example:
    | 'providers' => [
    |     'google' => [
    |         'type' => 'oidc',
    |         'client_id' => env('GOOGLE_CLIENT_ID'),
    |         'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    |         'authorization_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
    |         'token_url' => 'https://oauth2.googleapis.com/token',
    |         'jwks_uri' => 'https://www.googleapis.com/oauth2/v3/certs',
    |         'issuer' => 'https://accounts.google.com',
    |         'scopes' => ['openid', 'email', 'profile'],
    |         'redirect_uri' => null, // null = auto-resolve from router
    |     ],
    | ],
    |
    */

    'providers' => [],
];
