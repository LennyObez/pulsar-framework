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
        // Privacy-by-default: honor Do-Not-Track and strip referrer query
        // strings out of the box. The extension advertises GDPR / ePrivacy
        // compliance, so a fresh install must not silently track DNT users or
        // retain referrer query parameters. Opt out explicitly if you have a
        // legal basis to.
        'respect_dnt' => true,
        'anonymize_referrer' => true,

        // Days of per-day visitor salts to retain before they are destroyed.
        // Once a day's salt is purged its visitor hashes become irreversible
        // (forward secrecy). Floored at 2 so midnight session grace keeps
        // working. Lower is more private; higher lengthens the window in which
        // a data-subject-access request can still reconstruct a visitor's id.
        'visitor_salt_retention_days' => 2,
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
