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
use function sprintf;

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

            return $this->persistLocale(Response::redirect($redirectUrl, 301), $locale, $request);
        }

        $this->translator->locale = $locale;

        $uri = $request->getUri()->withPath($strippedPath);
        $request = $request
            ->withUri($uri)
            ->withAttribute('_locale', $locale)
            ->withAttribute('_locale_prefix', $locale);

        return $this->persistLocale($handler->handle($request), $locale, $request);
    }

    private function handleUnprefixedRequest(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        if ($this->config->courtesyRedirect && in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            $redirect = $this->courtesyRedirect($request);

            if ($redirect !== null) {
                return $redirect;
            }
        }

        $negotiated = $this->negotiator->negotiate(
            $request,
            $this->config->supportedLocales,
            $this->config->defaultLocale,
        );

        // The active locale for an unprefixed URL is URL-authoritative: it is the
        // default locale unless Accept-Language negotiation is explicitly allowed
        // to choose it. This keeps unprefixed (default-locale) URLs canonical so
        // they are not rewritten/redirected to a negotiated translation by the
        // localized-slug middleware. The negotiated preference is exposed
        // separately so an application can still offer a courtesy redirect at /.
        $locale = $this->config->negotiateUnprefixedLocale ? $negotiated : $this->config->defaultLocale;

        $this->translator->locale = $locale;

        $request = $request
            ->withAttribute('_locale', $locale)
            ->withAttribute('_negotiated_locale', $negotiated);

        $response = $handler->handle($request);

        // When the served locale is negotiated, the body depends on the request's
        // Accept-Language (and on the cookie/session once the cookie-aware
        // negotiator is wired), so a shared cache must key on them — otherwise it
        // could serve one visitor's locale to another.
        if ($this->config->negotiateUnprefixedLocale) {
            $vary = $this->config->localeCookieEnabled ? 'Accept-Language, Cookie' : 'Accept-Language';
            $response = $response->withAddedHeader('Vary', $vary);
        }

        return $response;
    }

    private function shouldCanonicalRedirect(ServerRequestInterface $request, string $locale): bool
    {
        return $locale === $this->config->defaultLocale
            && $this->config->canonicalRedirect
            && !$this->config->defaultLocaleInUrl
            && in_array($request->getMethod(), ['GET', 'HEAD'], true);
    }

    /**
     * Build a courtesy 302 to the visitor's negotiated locale prefix, or null
     * when no redirect should happen.
     *
     * The default locale is never redirected: its canonical URL is the
     * unprefixed one, so redirecting would loop with the canonical 301. Visitors
     * with no supported preference fall back to `courtesy_fallback_locale` (e.g.
     * `en`), which still leaves the default locale canonical.
     */
    private function courtesyRedirect(ServerRequestInterface $request): ?ResponseInterface
    {
        $arrival = $this->config->courtesyFallbackLocale !== ''
            ? $this->config->courtesyFallbackLocale
            : $this->config->defaultLocale;

        $target = $this->negotiator->negotiate($request, $this->config->supportedLocales, $arrival);

        if ($target === $this->config->defaultLocale || !in_array($target, $this->config->supportedLocales, true)) {
            return null;
        }

        $path = $request->getUri()->getPath();
        $targetPath = '/' . $target . ($path === '/' ? '' : $path);
        $query = $request->getUri()->getQuery();
        $url = $query !== '' ? $targetPath . '?' . $query : $targetPath;

        return Response::redirect($url, 302)->withHeader('Vary', 'Accept-Language, Cookie');
    }

    /**
     * Stamp the locale-preference cookie on a response (when persistence is
     * enabled), so a visitor's chosen locale survives across visits. The cookie
     * is a functional preference: server-set, HttpOnly (only the server reads
     * it), Path=/, SameSite=Lax, Secure on HTTPS, one-year lifetime, no tracking
     * payload. Skipped when the request already carries the same value, so the
     * header is not re-sent on every hit.
     */
    private function persistLocale(
        ResponseInterface $response,
        string $locale,
        ServerRequestInterface $request,
    ): ResponseInterface {
        if (!$this->config->localeCookieEnabled) {
            return $response;
        }

        $cookies = $request->getCookieParams();

        if (($cookies[$this->config->localeCookieName] ?? null) === $locale) {
            return $response;
        }

        $secure = $request->getUri()->getScheme() === 'https' ? '; Secure' : '';
        $cookie = sprintf(
            '%s=%s; Path=/; Max-Age=31536000; HttpOnly; SameSite=Lax%s',
            $this->config->localeCookieName,
            $locale,
            $secure,
        );

        return $response->withAddedHeader('Set-Cookie', $cookie);
    }
}
