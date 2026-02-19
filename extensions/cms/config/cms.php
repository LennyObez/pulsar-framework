<?php

declare(strict_types=1);

/**
 * CMS configuration.
 *
 * @see \Pulsar\Extension\Cms\Config\CmsConfig
 */
return [
    // BCP 47 default locale code
    'default_locale' => 'en',

    // All locale codes the CMS serves
    'supported_locales' => ['en'],

    // When false, default locale served at /{path} without prefix.
    // When true, all locales require /{locale}/{path}.
    'default_locale_in_url' => false,

    // Enable Draft → InReview → Approved → Published pipeline.
    // When false, editors can publish directly from Draft.
    'editorial_workflow' => false,

    // Enable append-only event log for content mutations (causal history).
    // Tables always exist; this controls whether writes go through the event store.
    'event_sourcing' => false,

    // Enable all-locale atomic content snapshots on publish.
    // Recommended for regulated environments (banking, healthcare, legal).
    'atomic_snapshots' => false,

    // Maximum page nesting depth for hierarchy cycle detection.
    'max_hierarchy_depth' => 10,

    // Caching configuration
    'cache' => [
        'page_cache_ttl_seconds' => 3600,
        'stampede_protection' => true,
        'early_recompute_beta' => 10,
        'stale_grace_period_seconds' => 300,
        'lock_timeout_seconds' => 5,
    ],
];
