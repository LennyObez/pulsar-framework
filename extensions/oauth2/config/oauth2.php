<?php

declare(strict_types=1);

/**
 * OAuth2/OIDC authorization server configuration.
 *
 * @see \Pulsar\Extension\OAuth2\Config\OAuth2Config
 */
return [
    // The issuer identifier (e.g., https://auth.example.com)
    // Must match the 'iss' claim in ID tokens
    'issuer' => getenv('OAUTH2_ISSUER') ?: '',

    // Access token lifetime in seconds (default: 15 minutes)
    'access_token_ttl' => 900,

    // Refresh token lifetime in seconds (default: 30 days)
    'refresh_token_ttl' => 2_592_000,

    // Authorization code lifetime in seconds (default: 10 minutes)
    'authorization_code_ttl' => 600,

    // Dynamic client registration (disabled by default for security)
    // Enabling requires additional audit events and allowlisted metadata schemas
    'dynamic_registration' => false,

    // Supported signing algorithms for ID tokens
    'signing_algorithms' => ['RS256', 'ES256'],

    // Whether to use pairwise subject identifiers (OIDC Core 8.1)
    'pairwise_subjects' => false,

    // Keyring key identifier for token signing
    'signing_key_id' => 'oauth_sign',

    // Token format: 'reference' (opaque stored tokens) or 'jwt' (self-contained)
    'token_format' => 'reference',
];
