<?php

declare(strict_types=1);

namespace Pulsar\I18n\Locale;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\I18nConfig;

/**
 * Generates locale-prefixed URLs and hreflang link sets.
 *
 * Provides the primary API for building locale-aware URLs in templates,
 * controllers, and locale switcher components. Delegates to a
 * {@see LocaleUrlResolverInterface} for alternate resolution when available,
 * falling back to simple prefix-swapping.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class LocaleUrlGenerator
{
    public function __construct(
        private UrlPrefixExtractor $extractor,
        private I18nConfig $config,
        private ?LocaleUrlResolverInterface $resolver = null,
    ) {}

    /**
     * Build a locale-prefixed URL path.
     *
     * If no locale is specified, uses the configured default locale.
     */
    #[NoDiscard]
    public function url(string $path, ?string $locale = null): string
    {
        $locale ??= $this->config->defaultLocale;

        return $this->extractor->buildPath(
            $path,
            $locale,
            $this->config->defaultLocale,
            $this->config->defaultLocaleInUrl,
        );
    }

    /**
     * Resolve alternate URLs for all supported locales.
     *
     * Delegates to the injected resolver if available, otherwise builds
     * alternates by prefix-swapping via the extractor.
     *
     * @return array<string, string> Locale tag => URL path
     */
    #[NoDiscard]
    public function alternates(string $currentPath, string $currentLocale): array
    {
        if ($this->resolver !== null) {
            return $this->resolver->resolveAlternates($currentPath, $currentLocale);
        }

        $alternates = [];

        foreach ($this->config->supportedLocales as $locale) {
            $alternates[$locale] = $this->extractor->buildPath(
                $currentPath,
                $locale,
                $this->config->defaultLocale,
                $this->config->defaultLocaleInUrl,
            );
        }

        return $alternates;
    }

    /**
     * Generate hreflang link objects for all supported locales.
     *
     * Includes an `x-default` entry pointing to the default locale's URL,
     * as recommended by search engines for locale discovery.
     *
     * @return list<HreflangLink>
     */
    #[NoDiscard]
    public function hreflangLinks(string $currentPath, string $currentLocale): array
    {
        $alternates = $this->alternates($currentPath, $currentLocale);

        $links = [];

        foreach ($alternates as $locale => $href) {
            $links[] = new HreflangLink(locale: $locale, href: $href);
        }

        $defaultHref = $alternates[$this->config->defaultLocale] ?? $currentPath;
        $links[] = new HreflangLink(locale: 'x-default', href: $defaultHref);

        return $links;
    }
}
