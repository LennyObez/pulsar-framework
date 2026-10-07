<?php

declare(strict_types=1);

namespace Pulsar\I18n\Locale;

use Override;
use Pulsar\Api\Api;
use Pulsar\Config\I18nConfig;

/**
 * Default locale URL resolver using simple prefix swapping.
 *
 * Resolves alternate-locale URLs by replacing the locale prefix in the
 * current path. Extensions (e.g. CMS) may register a content-aware
 * implementation that resolves translated slugs instead.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class RouteBasedLocaleUrlResolver implements LocaleUrlResolverInterface
{
    public function __construct(
        private UrlPrefixExtractor $extractor,
        private I18nConfig $config,
    ) {}

    #[Override]
    public function resolveAlternates(string $currentPath, string $currentLocale): array
    {
        $alternates = [];

        foreach ($this->config->supportedLocales as $locale) {
            $stripped = $this->extractor->stripPrefix($currentPath, $currentLocale);
            $alternates[$locale] = $this->extractor->buildPath(
                $stripped,
                $locale,
                $this->config->defaultLocale,
                $this->config->defaultLocaleInUrl,
            );
        }

        return $alternates;
    }
}
