<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\I18n;

use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;

/**
 * Generates hreflang alternate links for multilingual content.
 *
 * For each published translation of a content item, produces an HreflangLink
 * value object suitable for rendering as `<link rel="alternate">` tags in the
 * HTML head section. Also produces an `x-default` entry pointing to the
 * default locale version.
 */
#[Api(since: '1.0.0')]
final readonly class HreflangGenerator
{
    public function __construct(
        private ContentTranslationRepositoryInterface $translationRepository,
        private LocaleResolver $localeResolver,
    ) {}

    /**
     * Generate hreflang links for a content item.
     *
     * Returns links for all supported locales that have a published translation,
     * plus an x-default entry for the default locale version.
     *
     * @param Content $content The content aggregate root
     * @param string $currentLocale The currently active locale
     * @param CmsConfig $config CMS configuration with locale settings
     * @return list<HreflangLink> Hreflang links for available translations
     */
    public function generate(Content $content, string $currentLocale, CmsConfig $config): array
    {
        $translations = $this->translationRepository->findByContentId($content->id);

        if ($translations === []) {
            return [];
        }

        // Index translations by locale for quick lookup
        $translationsByLocale = [];

        foreach ($translations as $translation) {
            $translationsByLocale[$translation->locale] = $translation;
        }

        $links = [];
        $defaultLocaleHref = null;

        foreach ($config->supportedLocales as $locale) {
            if (!isset($translationsByLocale[$locale])) {
                continue;
            }

            $translation = $translationsByLocale[$locale];
            $href = $this->localeResolver->buildPath($translation->path, $locale, $config);

            $links[] = new HreflangLink(
                locale: $locale,
                href: $href,
            );

            if ($locale === $config->defaultLocale) {
                $defaultLocaleHref = $href;
            }
        }

        // Add x-default pointing to the default locale version
        if ($defaultLocaleHref !== null) {
            $links[] = new HreflangLink(
                locale: 'x-default',
                href: $defaultLocaleHref,
            );
        }

        return $links;
    }
}
