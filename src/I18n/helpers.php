<?php

declare(strict_types=1);

use Pulsar\I18n\Exception\I18nException;
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
