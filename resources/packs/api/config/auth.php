<?php

declare(strict_types=1);

/**
 * API authentication configuration.
 *
 * Defines the authentication strategies available for API endpoints.
 *
 * WARNING: This is a scaffold configuration. You MUST implement proper
 * authentication before exposing your API to production traffic.
 */
return [
    'auth' => [
        'default' => 'api_key',

        'guards' => [
            'api_key' => [
                'driver' => 'api_key',
                'header' => 'X-API-Key',
                'query_param' => 'api_key',
            ],
            'bearer' => [
                'driver' => 'bearer_token',
                'header' => 'Authorization',
                'prefix' => 'Bearer',
            ],
        ],

        'api_keys' => [
            'storage' => 'database',
            'hash_algorithm' => 'sha256',
            'prefix' => 'pk_',
        ],

        'jwt' => [
            'algorithm' => 'RS256',
            'public_key_env' => 'JWT_PUBLIC_KEY',
            'private_key_env' => 'JWT_PRIVATE_KEY',
            'ttl_seconds' => 3600,
            'refresh_ttl_seconds' => 86400,
            'issuer' => null,
        ],
    ],
];
