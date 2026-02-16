<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Api;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Newsletter\FeedGeneratorServiceInterface;
use Pulsar\Http\Message\Response;

use function is_string;
use function max;
use function min;

/**
 * Feed API controller for RSS and Atom syndication feeds.
 *
 * Generates feeds for a given content type slug with configurable
 * locale, limit, and format. Responses are cached with a 60-minute TTL.
 */
#[Internal(reason: 'CMS REST API controller; implementation detail')]
final readonly class NewsletterFeedController
{
    private const int CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private FeedGeneratorServiceInterface $feedGenerator,
    ) {}

    /**
     * GET /api/cms/feed/{contentType}/rss
     *
     * Returns an RSS 2.0 feed for the given content type.
     */
    public function rss(ServerRequestInterface $request, string $contentType): Response
    {
        $locale = $this->resolveLocale($request);
        $limit = $this->resolveLimit($request);

        $xml = $this->feedGenerator->generate($contentType, $locale, 'rss', $limit);

        return new Response(
            headers: [
                'Content-Type' => 'application/rss+xml; charset=utf-8',
                'Cache-Control' => 'public, max-age=' . self::CACHE_TTL_SECONDS,
            ],
            body: $xml,
        );
    }

    /**
     * GET /api/cms/feed/{contentType}/atom
     *
     * Returns an Atom 1.0 feed for the given content type.
     */
    public function atom(ServerRequestInterface $request, string $contentType): Response
    {
        $locale = $this->resolveLocale($request);
        $limit = $this->resolveLimit($request);

        $xml = $this->feedGenerator->generate($contentType, $locale, 'atom', $limit);

        return new Response(
            headers: [
                'Content-Type' => 'application/atom+xml; charset=utf-8',
                'Cache-Control' => 'public, max-age=' . self::CACHE_TTL_SECONDS,
            ],
            body: $xml,
        );
    }

    private function resolveLocale(ServerRequestInterface $request): string
    {
        $params = $request->getQueryParams();
        $locale = $params['locale'] ?? null;

        return is_string($locale) && $locale !== '' ? $locale : 'en';
    }

    private function resolveLimit(ServerRequestInterface $request): int
    {
        $params = $request->getQueryParams();

        /** @var int|string $rawLimit */
        $rawLimit = $params['limit'] ?? 20;

        return min(100, max(1, (int) $rawLimit));
    }
}
