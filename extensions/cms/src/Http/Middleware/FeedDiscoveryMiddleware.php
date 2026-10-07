<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Http\Middleware\MiddlewareInterface;

use function str_contains;

/**
 * Injects RSS/Atom feed auto-discovery Link headers into HTML responses.
 *
 * Adds Link response headers with rel="alternate" pointing to RSS and Atom
 * feeds so that browsers and feed readers can auto-discover available feeds.
 * Only applies to HTML responses (Content-Type: text/html).
 *
 * @psalm-api Registered with the router middleware pipeline by the
 *            CmsCoreServiceProvider; not new'd by name.
 */
#[Internal(reason: 'CMS HTTP middleware; implementation detail')]
final readonly class FeedDiscoveryMiddleware implements MiddlewareInterface
{
    public function __construct(
        private CmsConfig $config,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $contentType = $response->getHeaderLine('Content-Type');

        if (!str_contains($contentType, 'text/html')) {
            return $response;
        }

        $locale = $this->config->defaultLocale;

        return $response
            ->withAddedHeader(
                'Link',
                '</feed/rss>; rel="alternate"; type="application/rss+xml"; title="RSS Feed (' . $locale . ')"',
            )
            ->withAddedHeader(
                'Link',
                '</feed/atom>; rel="alternate"; type="application/atom+xml"; title="Atom Feed (' . $locale . ')"',
            );
    }
}
