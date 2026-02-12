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
];
