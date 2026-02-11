<?php

declare(strict_types=1);

/**
 * Rate limiting configuration.
 *
 * Controls request rate limits per client to prevent abuse and
 * ensure fair usage of API resources.
 *
 * WARNING: These are scaffold defaults. Tune rate limits based on
 * your actual API capacity and usage patterns.
 */
return [
    'rate_limiting' => [
        'enabled' => true,

        'storage' => 'memory',

        'default_limits' => [
            'requests_per_minute' => 60,
            'requests_per_hour' => 1000,
        ],

        'tiers' => [
            'free' => [
                'requests_per_minute' => 30,
                'requests_per_hour' => 500,
            ],
            'standard' => [
                'requests_per_minute' => 60,
                'requests_per_hour' => 2000,
            ],
            'premium' => [
                'requests_per_minute' => 120,
                'requests_per_hour' => 10000,
            ],
        ],

        'headers' => [
            'limit' => 'X-RateLimit-Limit',
            'remaining' => 'X-RateLimit-Remaining',
            'reset' => 'X-RateLimit-Reset',
        ],

        'exceeded_response' => [
            'status_code' => 429,
            'message' => 'Too many requests. Please try again later.',
        ],
    ],
];
