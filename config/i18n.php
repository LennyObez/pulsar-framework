<?php

declare(strict_types=1);

/**
 * Internationalization Configuration
 *
 * Locale and translation settings for Pulsar.
 * Environment variable `APP_LOCALE` overrides the default locale.
 *
 * @package Pulsar\Config
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Default Locale
    |--------------------------------------------------------------------------
    | The default locale used when no locale is explicitly set.
    | Override with APP_LOCALE in .env.
    */
    'default_locale' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Supported Locales
    |--------------------------------------------------------------------------
    | The list of locales this application supports.
    */
    'supported_locales' => ['en'],

    /*
    |--------------------------------------------------------------------------
    | Fallback Locales
    |--------------------------------------------------------------------------
    | Locales to try when a translation key is missing from the active locale.
    */
    'fallback_locales' => ['en'],

    /*
    |--------------------------------------------------------------------------
    | Catalog Path
    |--------------------------------------------------------------------------
    | Path to translation catalog files. Null uses the default location.
    */
    'catalog_path' => null,

    /*
    |--------------------------------------------------------------------------
    | Regulated Mode
    |--------------------------------------------------------------------------
    | When true, enables audit-grade translation tracking for regulated
    | domains (banking, healthcare, legal). Override with I18N_REGULATED.
    */
    'regulated' => false,

    /*
    |--------------------------------------------------------------------------
    | Max Supported Locales
    |--------------------------------------------------------------------------
    | Upper bound on the number of locales that can be loaded at runtime.
    */
    'max_supported_locales' => 50,

    /*
    |--------------------------------------------------------------------------
    | Strict Mode
    |--------------------------------------------------------------------------
    | When true, missing translation keys throw exceptions instead of
    | returning the raw key.
    */
    'strict_mode' => false,

    /*
    |--------------------------------------------------------------------------
    | URL Strategy
    |--------------------------------------------------------------------------
    | Controls how locale information is encoded in URLs.
    | 'none' — no URL-based locale routing (default).
    | 'path_prefix' — locale as first path segment (e.g., /fr/about).
    */
    'url_strategy' => 'none',

    /*
    |--------------------------------------------------------------------------
    | Default Locale in URL
    |--------------------------------------------------------------------------
    | When url_strategy is 'path_prefix', controls whether the default
    | locale appears as a prefix. When false, /about is the default
    | locale and /fr/about is French. When true, /en/about is English.
    */
    'default_locale_in_url' => false,

    /*
    |--------------------------------------------------------------------------
    | Canonical Redirect
    |--------------------------------------------------------------------------
    | When url_strategy is 'path_prefix' and default_locale_in_url is
    | false, redirect /en/about → /about with a 301 (GET/HEAD only).
    */
    'canonical_redirect' => true,
];
