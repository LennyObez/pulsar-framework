<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Seo\RobotsTxtGeneratorInterface;
use Pulsar\Http\Message\Response;

/**
 * Serves robots.txt and other SEO-related public endpoints.
 */
#[Internal(reason: 'CMS HTTP controller — implementation detail')]
final readonly class SeoController
{
    public function __construct(
        private RobotsTxtGeneratorInterface $robotsTxtGenerator,
    ) {}

    public function robotsTxt(ServerRequestInterface $request): Response
    {
        $uri = $request->getUri();
        $baseUrl = $uri->getScheme() . '://' . $uri->getHost();
        $port = $uri->getPort();

        if ($port !== null && $port !== 80 && $port !== 443) {
            $baseUrl .= ':' . $port;
        }

        $content = $this->robotsTxtGenerator->generate($baseUrl);

        return Response::text($content)
            ->withHeader('Cache-Control', 'public, max-age=86400');
    }
}
