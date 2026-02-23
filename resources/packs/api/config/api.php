<?php

declare(strict_types=1);

/**
 * API configuration.
 *
 * General settings for API behavior, versioning, and response format.
 */
return [
    'api' => [
        'default_version' => 'v1',

        'versioning' => [
            'strategy' => 'url_prefix',
            'header' => 'Accept',
            'vendor' => 'pulsar',
        ],

        'response' => [
            'format' => 'json',
            'envelope' => true,
            'include_request_id' => true,
        ],

        'pagination' => [
            'default_per_page' => 25,
            'max_per_page' => 100,
            'page_param' => 'page',
            'per_page_param' => 'per_page',
        ],

        'cors' => [
            'enabled' => true,
            'allowed_origins' => ['*'],
            'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'X-API-Key'],
            'exposed_headers' => ['X-Request-Id', 'X-RateLimit-Remaining'],
            'max_age' => 86400,
            'supports_credentials' => false,
        ],
    ],
];
