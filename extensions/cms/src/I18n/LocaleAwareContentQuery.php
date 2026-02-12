<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\I18n;

use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;

use function array_key_exists;
use function in_array;

/**
 * Locale-aware content query helper.
 *
 * Wraps translation repository queries with locale fallback behavior:
 * when a translation is not available in the requested locale, optionally
 * falls back to the default locale. Also provides locale availability
 * introspection for admin UI locale tab support.
 */
#[Internal(reason: 'CMS i18n query helper — implementation detail')]
final readonly class LocaleAwareContentQuery
{
    public function __construct(
        private ContentTranslationRepositoryInterface $translationRepository,
        private CmsConfig $config,
    ) {}

    /**
     * Find translation for a content item in the given locale, with optional fallback.
     *
     * When fallbackToDefault is true and no translation exists for the
     * requested locale, returns the default locale translation instead.
     */
    public function findTranslation(
        string $contentId,
        string $locale,
        bool $fallbackToDefault = false,
    ): ?ContentTranslation {
        $translation = $this->translationRepository->findByContentAndLocale($contentId, $locale);

        if ($translation !== null) {
            return $translation;
        }

        if ($fallbackToDefault && $locale !== $this->config->defaultLocale) {
            return $this->translationRepository->findByContentAndLocale(
                $contentId,
                $this->config->defaultLocale,
            );
        }

        return null;
    }

    /**
     * Find translation by path with optional locale fallback.
     */
    public function findByPath(
        string $locale,
        string $path,
        ?string $tenantId = null,
        bool $fallbackToDefault = false,
    ): ?ContentTranslation {
        $translation = $this->translationRepository->findByPath($locale, $path, $tenantId);

        if ($translation !== null) {
            return $translation;
        }

        if ($fallbackToDefault && $locale !== $this->config->defaultLocale) {
            return $this->translationRepository->findByPath(
                $this->config->defaultLocale,
                $path,
                $tenantId,
            );
        }

        return null;
    }

    /**
     * Get all translations for a content item, indexed by locale.
     *
     * @return array<string, ContentTranslation>
     */
    public function allTranslations(string $contentId): array
    {
        $translations = $this->translationRepository->findByContentId($contentId);
        $indexed = [];

        foreach ($translations as $translation) {
            $indexed[$translation->locale] = $translation;
        }

        return $indexed;
    }

    /**
     * Get locale availability status for a content item.
     *
     * Returns a map of each supported locale to a boolean indicating
     * whether a translation exists. Used for admin locale tab rendering.
     *
     * @return array<string, bool>
     */
    public function localeAvailability(string $contentId): array
    {
        $translations = $this->allTranslations($contentId);
        $availability = [];

        foreach ($this->config->supportedLocales as $locale) {
            $availability[$locale] = array_key_exists($locale, $translations);
        }

        return $availability;
    }

    /**
     * Get the list of locales that have translations for a content item.
     *
     * @return list<string>
     */
    public function translatedLocales(string $contentId): array
    {
        $translations = $this->translationRepository->findByContentId($contentId);
        $locales = [];

        foreach ($translations as $translation) {
            if (in_array($translation->locale, $this->config->supportedLocales, true)) {
                $locales[] = $translation->locale;
            }
        }

        return $locales;
    }

    /**
     * Get the list of locales that are missing translations for a content item.
     *
     * @return list<string>
     */
    public function missingLocales(string $contentId): array
    {
        $translated = $this->translatedLocales($contentId);
        $missing = [];

        foreach ($this->config->supportedLocales as $locale) {
            if (!in_array($locale, $translated, true)) {
                $missing[] = $locale;
            }
        }

        return $missing;
    }
}
