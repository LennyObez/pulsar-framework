<?php

declare(strict_types=1);

use Pulsar\I18n\Exception\I18nException;
use Pulsar\I18n\Locale\LocalizedUrlGenerator;
use Pulsar\I18n\Translator;

if (!function_exists('__')) {
    /**
     * Translate a message key.
     *
     * @param string $key Translation key
     * @param array<string, mixed> $parameters ICU parameters
     * @param ?string $locale Override locale
     * @param string $domain Translation domain
     *
     * @throws I18nException If i18n is not booted
     */
    function __(string $key, array $parameters = [], ?string $locale = null, string $domain = 'messages'): string
    {
        return Translator::getGlobalInstance()->translate($key, $parameters, $locale, $domain);
    }
}

if (!function_exists('t')) {
    /**
     * Translate a message key (short alias for __()).
     *
     * This function exists so that @t() in Pulse templates works correctly
     * even when nested inside other directive arguments (e.g., @section('title', @t('key'))),
     * where the compiler cannot recursively process inner directives.
     *
     * @param string $key Translation key
     * @param array<string, mixed> $parameters ICU parameters
     * @param ?string $locale Override locale
     * @param string $domain Translation domain
     *
     * @throws I18nException If i18n is not booted
     */
    function t(string $key, array $parameters = [], ?string $locale = null, string $domain = 'messages'): string
    {
        return Translator::getGlobalInstance()->translate($key, $parameters, $locale, $domain);
    }
}

if (!function_exists('tRaw')) {
    /**
     * Translate a message key without HTML escaping (alias for __()).
     *
     * Use for translations containing trusted HTML (links, formatting).
     * Falls back to the same translation mechanism as t() and __().
     *
     * @param string $key Translation key
     * @param array<string, mixed> $parameters ICU parameters
     * @param ?string $locale Override locale
     * @param string $domain Translation domain
     *
     * @throws I18nException If i18n is not booted
     */
    function tRaw(string $key, array $parameters = [], ?string $locale = null, string $domain = 'messages'): string
    {
        return Translator::getGlobalInstance()->translate($key, $parameters, $locale, $domain);
    }
}

if (!function_exists('trans')) {
    /**
     * Translate a message key (alias for __()).
     *
     * @param string $key Translation key
     * @param array<string, mixed> $parameters ICU parameters
     * @param ?string $locale Override locale
     * @param string $domain Translation domain
     *
     * @throws I18nException If i18n is not booted
     */
    function trans(string $key, array $parameters = [], ?string $locale = null, string $domain = 'messages'): string
    {
        return Translator::getGlobalInstance()->translate($key, $parameters, $locale, $domain);
    }
}

if (!function_exists('route')) {
    /**
     * Generate a localized URL for a route key.
     *
     * Translates the key's URL slug for the target locale (or the current
     * request locale when none is given) and applies the locale prefix per
     * the configured URL strategy. Backs the @route directive.
     *
     * @param string $key Canonical route key (e.g. 'development/projects')
     * @param array<string, string> $params Route parameter values
     * @param ?string $locale Target locale; defaults to the current locale
     *
     * @throws I18nException If localized routing is not booted
     */
    function route(string $key, array $params = [], ?string $locale = null): string
    {
        return LocalizedUrlGenerator::getGlobalInstance()->route($key, $params, $locale);
    }
}
