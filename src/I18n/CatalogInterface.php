<?php

declare(strict_types=1);

namespace Pulsar\I18n;

use Pulsar\Api\Api;

/**
 * Translation catalog backend contract.
 *
 * Catalogs provide access to translation entries organized
 * by locale and domain.
 */
#[Api(since: '1.0.0')]
interface CatalogInterface
{
    /**
     * Get a translation entry.
     *
     * @return ?TranslationEntry Null if not found
     */
    public function get(string $key, string $locale, string $domain = 'messages'): ?TranslationEntry;

    /**
     * Check if a translation key exists.
     */
    public function has(string $key, string $locale, string $domain = 'messages'): bool;

    /**
     * Get all entries for a locale and domain.
     *
     * @return array<string, TranslationEntry>
     */
    public function all(string $locale, string $domain = 'messages'): array;
}
