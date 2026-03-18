<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Seo\FeedGeneratorInterface;
use Pulsar\Http\Message\Response;

use function is_int;
use function max;
use function min;

/**
 * Serves RSS and Atom feeds for published content.
 *
 * @psalm-api Bound to /feed/* routes by the CMS service provider;
 *            resolved from the DI container by the router.
 */
#[Internal(reason: 'CMS HTTP controller; implementation detail')]
final readonly class FeedController
{
    public function __construct(
        private FeedGeneratorInterface $feedGenerator,
    ) {}

    public function rss(ServerRequestInterface $request, string $locale): Response
    {
        $baseUrl = $this->resolveBaseUrl($request);
        $limit = $this->resolveLimit($request);
        $xml = $this->feedGenerator->generateRss($locale, $baseUrl, $limit);

        return new Response(
            headers: [
                'Content-Type' => 'application/rss+xml; charset=utf-8',
                'Cache-Control' => 'public, max-age=1800',
            ],
            body: $xml,
        );
    }

    public function atom(ServerRequestInterface $request, string $locale): Response
    {
        $baseUrl = $this->resolveBaseUrl($request);
        $limit = $this->resolveLimit($request);
        $xml = $this->feedGenerator->generateAtom($locale, $baseUrl, $limit);

        return new Response(
            headers: [
                'Content-Type' => 'application/atom+xml; charset=utf-8',
                'Cache-Control' => 'public, max-age=1800',
            ],
            body: $xml,
        );
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

    private function resolveLimit(ServerRequestInterface $request): int
    {
        $params = $request->getQueryParams();

        return min(100, max(1, is_int($params['limit'] ?? null) ? $params['limit'] : 20));
    }
}
