<?php

declare(strict_types=1);

namespace Pulsar\I18n\Locale;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Config\I18nConfig;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\I18n\LocaleNegotiatorInterface;
use Pulsar\I18n\TranslatorInterface;

use function in_array;

/**
 * HTTP middleware that detects locale from URL path prefixes.
 *
 * When a supported locale is found as the first path segment (e.g., `/fr/about`),
 * the middleware strips the prefix, sets `_locale` and `_locale_prefix` request
 * attributes, and updates the translator. Optionally issues a 301 canonical
 * redirect when the default locale appears in the URL.
 *
 * Falls back to {@see LocaleNegotiatorInterface} for Accept-Language negotiation
 * when no URL prefix is present.
 */
#[Internal]
final readonly class LocalePrefixMiddleware implements MiddlewareInterface
{
    public function __construct(
        private UrlPrefixExtractor $extractor,
        private LocaleNegotiatorInterface $negotiator,
        private I18nConfig $config,
        private TranslatorInterface $translator,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $locale = $this->extractor->extract($path, $this->config->supportedLocales);

        if ($locale !== null) {
            return $this->handlePrefixedRequest($request, $handler, $path, $locale);
        }

        return $this->handleUnprefixedRequest($request, $handler);
    }

    private function handlePrefixedRequest(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
        string $path,
        string $locale,
    ): ResponseInterface {
        $strippedPath = $this->extractor->stripPrefix($path, $locale);

        if ($this->shouldCanonicalRedirect($request, $locale)) {
            $query = $request->getUri()->getQuery();
            $redirectUrl = $query !== '' ? $strippedPath . '?' . $query : $strippedPath;

            return Response::redirect($redirectUrl, 301);
        }

        $this->translator->locale = $locale;

        $uri = $request->getUri()->withPath($strippedPath);
        $request = $request
            ->withUri($uri)
            ->withAttribute('_locale', $locale)
            ->withAttribute('_locale_prefix', $locale);

        return $handler->handle($request);
    }

    private function handleUnprefixedRequest(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $locale = $this->negotiator->negotiate(
            $request,
            $this->config->supportedLocales,
            $this->config->defaultLocale,
        );

        $this->translator->locale = $locale;

        $request = $request->withAttribute('_locale', $locale);

        return $handler->handle($request);
    }

    private function shouldCanonicalRedirect(ServerRequestInterface $request, string $locale): bool
    {
        return $locale === $this->config->defaultLocale
            && $this->config->canonicalRedirect
            && !$this->config->defaultLocaleInUrl
            && in_array($request->getMethod(), ['GET', 'HEAD'], true);
    }
}
