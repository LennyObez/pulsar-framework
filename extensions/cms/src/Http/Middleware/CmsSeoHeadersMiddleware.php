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

use function is_string;

/**
 * Adds SEO-relevant HTTP headers to CMS responses.
 *
 * Applies X-Robots-Tag, Link (canonical), and content language headers
 * based on the CMS configuration and the current request context.
 */
#[Internal(reason: 'CMS middleware; not a public API surface')]
final readonly class CmsSeoHeadersMiddleware implements MiddlewareInterface
{
    public function __construct(
        private CmsConfig $config,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        // Add X-Robots-Tag header with the configured default
        $response = $response->withHeader('X-Robots-Tag', $this->config->seo->defaultRobots);

        // Add Content-Language header from the locale attribute
        $locale = $request->getAttribute('locale');

        if (is_string($locale) && $locale !== '') {
            $response = $response->withHeader('Content-Language', $locale);
        }

        return $response;
    }
}
