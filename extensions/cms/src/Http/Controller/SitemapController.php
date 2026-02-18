<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Seo\SitemapGeneratorInterface;
use Pulsar\Http\Message\Response;

/**
 * Serves XML sitemap files (index and per-type pages).
 */
#[Internal(reason: 'CMS HTTP controller — implementation detail')]
final readonly class SitemapController
{
    public function __construct(
        private SitemapGeneratorInterface $sitemapGenerator,
    ) {}

    public function index(ServerRequestInterface $request): Response
    {
        $baseUrl = $this->resolveBaseUrl($request);
        $xml = $this->sitemapGenerator->generateIndex($baseUrl);

        return new Response(
            statusCode: 200,
            headers: [
                'Content-Type' => 'application/xml; charset=utf-8',
                'Cache-Control' => 'public, max-age=3600',
            ],
            body: $xml,
        );
    }

    public function forType(ServerRequestInterface $request, string $contentType, int $page = 1): Response
    {
        $baseUrl = $this->resolveBaseUrl($request);
        $xml = $this->sitemapGenerator->generateForType($contentType, $baseUrl, $page);

        return new Response(
            statusCode: 200,
            headers: [
                'Content-Type' => 'application/xml; charset=utf-8',
                'Cache-Control' => 'public, max-age=3600',
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
}
