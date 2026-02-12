<?php

declare(strict_types=1);

namespace Pulsar\I18n;

use Pulsar\Api\Api;

/**
 * Core translation contract.
 *
 * Translates message keys into localized strings, with optional
 * ICU MessageFormat parameter substitution.
 */
#[Api(since: '1.0.0')]
interface TranslatorInterface
{
    /**
     * Translate a message key.
     *
     * @param string $key The translation key
     * @param array<string, mixed> $parameters ICU MessageFormat parameters
     * @param ?string $locale Override locale (null = current locale)
     * @param string $domain Translation domain (file/namespace)
     */
    public function translate(string $key, array $parameters = [], ?string $locale = null, string $domain = 'messages'): string;

    /**
     * Get the current locale.
     */
    public function getLocale(): string;

    /**
     * Set the current locale.
     */
    public function setLocale(string $locale): void;

    /**
     * Check if a translation key exists.
     */
    public function has(string $key, ?string $locale = null, string $domain = 'messages'): bool;
}
