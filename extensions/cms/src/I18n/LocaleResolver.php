<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\I18n;

use NoDiscard;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\I18n\Locale\UrlPrefixExtractor;

/**
 * Resolves the active locale from an HTTP request.
 *
 * Extracts the locale from the URL path prefix using the core
 * UrlPrefixExtractor, falling back to the CMS default locale
 * when no supported locale is found in the path.
 */
#[Api(since: '1.0.0')]
final readonly class LocaleResolver
{
    public function __construct(
        private UrlPrefixExtractor $extractor = new UrlPrefixExtractor(),
    ) {}

    /**
     * Resolve the locale for the given request.
     *
     * Checks the URL path prefix against the configured supported locales.
     * Returns the matched locale or the default locale if no prefix matches.
     */
    #[NoDiscard]
    public function resolve(ServerRequestInterface $request, CmsConfig $config): string
    {
        $path = $request->getUri()->getPath();
        $extracted = $this->extractor->extract($path, $config->supportedLocales);

        return $extracted ?? $config->defaultLocale;
    }
}
