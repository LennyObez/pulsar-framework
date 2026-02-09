<?php

declare(strict_types=1);

/**
 * Analytics extension configuration.
 *
 * Copy this file to your application's config/ directory and adjust as needed.
 */
return [
    'enabled' => true,

    'collection' => [
        'driver' => 'direct', // 'direct' or 'queue'
    ],

    'privacy' => [
        'respect_dnt' => false,
        'anonymize_referrer' => false,
    ],

    'tracking' => [
        'tracker_endpoint' => '/plsr/api/event',
        'script_endpoint' => '/plsr/js/tracker.js',
        'extensions' => [],
    ],

    'retention' => [
        'raw_days' => 90,
        'aggregated_days' => 730,
        'hourly_hours' => 48,
    ],

    'rate_limit' => [
        'max_events_per_ip_per_minute' => 30,
        'burst' => 5,
    ],
];
