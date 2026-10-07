<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Api;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Seo\FeedGeneratorInterface;
use Pulsar\Http\Message\Response;

use function in_array;

/**
 * Serves RSS 2.0 and Atom 1.0 feeds for published CMS content.
 *
 * Routes:
 *   GET /feed/rss      : RSS 2.0 feed (default locale)
 *   GET /feed/atom     : Atom 1.0 feed (default locale)
 *   GET /{locale}/feed/rss : RSS 2.0 feed for locale
 *   GET /{locale}/feed/atom: Atom 1.0 feed for locale
 */
#[Internal(reason: 'CMS HTTP controller; implementation detail')]
final readonly class FeedController
{
    private const int CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private FeedGeneratorInterface $feedGenerator,
        private CmsConfig $config,
    ) {}

    public function rss(ServerRequestInterface $request): Response
    {
        $locale = $this->resolveLocale($request);

        if ($locale === null) {
            return Response::json(['error' => 'Unsupported locale'], 404);
        }

        $baseUrl = $this->resolveBaseUrl($request);
        $xml = $this->feedGenerator->generateRss($locale, $baseUrl);

        return new Response(
            statusCode: 200,
            headers: [
                'Content-Type' => 'application/rss+xml; charset=utf-8',
                'Cache-Control' => 'public, max-age=' . self::CACHE_TTL_SECONDS,
            ],
            body: $xml,
        );
    }

    public function atom(ServerRequestInterface $request): Response
    {
        $locale = $this->resolveLocale($request);

        if ($locale === null) {
            return Response::json(['error' => 'Unsupported locale'], 404);
        }

        $baseUrl = $this->resolveBaseUrl($request);
        $xml = $this->feedGenerator->generateAtom($locale, $baseUrl);

        return new Response(
            statusCode: 200,
            headers: [
                'Content-Type' => 'application/atom+xml; charset=utf-8',
                'Cache-Control' => 'public, max-age=' . self::CACHE_TTL_SECONDS,
            ],
            body: $xml,
        );
    }

    private function resolveLocale(ServerRequestInterface $request): ?string
    {
        /** @var string|null $locale */
        $locale = $request->getAttribute('locale');

        if ($locale === null || $locale === '') {
            return $this->config->defaultLocale;
        }

        if (!in_array($locale, $this->config->supportedLocales, true)) {
            return null;
        }

        return $locale;
    }

    private function resolveBaseUrl(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $scheme = $uri->getScheme();
        $host = $uri->getHost();
        $port = $uri->getPort();

        $base = $scheme . '://' . $host;

        if ($port !== null && $port !== 80 && $port !== 443) {
            $base .= ':' . $port;
        }

        return $base;
    }
}
