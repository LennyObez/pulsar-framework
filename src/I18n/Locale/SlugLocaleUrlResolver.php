<?php

declare(strict_types=1);

namespace Pulsar\I18n\Locale;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Config\I18nConfig;

/**
 * Locale URL resolver that emits translated slugs.
 *
 * Bound to {@see LocaleUrlResolverInterface} when localized slugs are
 * configured, so the path-based {@see LocaleUrlGenerator} (and therefore
 * `hreflangLinks()` and locale switchers) produces per-locale slug URLs
 * instead of bare prefix swaps.
 *
 * Resolution maps the supplied path back to a route key — accepting either
 * the canonical key path (what the application sees after the slug-rewrite
 * middleware) or a locale's localized slug path — then rebuilds the URL for
 * every supported locale from its slug. Paths that match no key fall back to
 * prefix swapping, preserving the previous behaviour for non-keyed routes.
 */
#[Internal(reason: 'i18n slug-aware locale URL resolution; bound via DI')]
final readonly class SlugLocaleUrlResolver implements LocaleUrlResolverInterface
{
    public function __construct(
        private SlugRegistry $registry,
        private I18nConfig $config,
        private UrlPrefixExtractor $extractor,
    ) {}

    #[Override]
    public function resolveAlternates(string $currentPath, string $currentLocale): array
    {
        $stripped = $this->extractor->stripPrefix($currentPath, $currentLocale);
        $match = $this->resolveKey($stripped, $currentLocale);

        if ($match === null) {
            return $this->prefixSwapAlternates($stripped);
        }

        $alternates = [];

        foreach ($this->config->supportedLocales as $locale) {
            $localizedPath = '/' . $this->registry->slugFor($match->key, $locale);

            if ($match->remainder !== '') {
                $localizedPath .= '/' . $match->remainder;
            }

            $alternates[$locale] = $this->extractor->buildPath(
                $localizedPath,
                $locale,
                $this->config->defaultLocale,
                $this->config->defaultLocaleInUrl,
            );
        }

        return $alternates;
    }

    /**
     * Resolve a path to a route key, accepting canonical key paths or the
     * current locale's localized slugs.
     */
    private function resolveKey(string $path, string $currentLocale): ?SlugMatch
    {
        return $this->registry->matchKey($path)
            ?? $this->registry->matchLocalized($currentLocale, $path);
    }

    /**
     * Legacy prefix-swap behaviour for paths that map to no registered key.
     *
     * @return array<string, string>
     */
    private function prefixSwapAlternates(string $strippedPath): array
    {
        $alternates = [];

        foreach ($this->config->supportedLocales as $locale) {
            $alternates[$locale] = $this->extractor->buildPath(
                $strippedPath,
                $locale,
                $this->config->defaultLocale,
                $this->config->defaultLocaleInUrl,
            );
        }

        return $alternates;
    }
}
