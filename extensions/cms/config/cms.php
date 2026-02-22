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

    // Commerce subsystem. Set to an array to enable, or null/omit to disable.
    // When enabled, product/order/invoice/checkout routes become available.
    // 'commerce' => [
    //     // Tax rate rules applied per item and per country
    //     'taxRates' => [
    //         // ['country' => 'BE', 'rate' => 21.0, 'name' => 'BTW'],
    //         // ['country' => 'DE', 'rate' => 19.0, 'name' => 'MwSt'],
    //     ],
    //
    //     // Invoice renderer type: 'html' (default) or a custom renderer class
    //     'invoiceRenderer' => 'html',
    //
    //     // Days until digital download tokens expire
    //     'downloadTokenExpiryDays' => 30,
    //
    //     // Maximum number of downloads per digital purchase
    //     'maxDownloads' => 5,
    //
    //     // Whether tax calculation is mandatory for all orders
    //     'taxRequired' => false,
    //
    //     // Default ISO 4217 currency code
    //     'currency' => 'EUR',
    // ],

    // Live CSS editor configuration
    'live_css' => [
        // Whether the Live CSS editor is enabled
        'enabled' => true,

        // Maximum allowed CSS content length in characters
        'max_css_length' => 100_000,

        // Whether @font-face with external URLs is permitted
        'allow_external_fonts' => false,
    ],

    // Import/export configuration
    'import' => [
        // Maximum allowed import file size in bytes (default: 50 MB)
        'max_import_size_bytes' => 50 * 1024 * 1024,

        // Whether to download media from external URLs during import
        'allow_external_media_download' => true,

        // Whether imports default to dry-run mode (recommended)
        'dry_run_default' => true,
    ],
];
