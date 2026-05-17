<?php

declare(strict_types=1);

namespace Pulsar\Documentation;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Api;
use Pulsar\Http\Middleware\MiddlewareInterface;

use function preg_match;

/**
 * Middleware that resolves the documentation version from the URL path.
 *
 * Matches URLs like /docs/{version}/{slug} and attaches the resolved
 * DocVersion to the request attributes.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DocVersionResolverMiddleware implements MiddlewareInterface
{
    public function __construct(
        private DocVersionRegistry $registry,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if (preg_match('#^/docs/([^/]+)(?:/(.+))?$#', $path, $matches) === 1) {
            $versionSlug = $matches[1];
            $docVersion = $this->registry->resolve($versionSlug);

            $request = $request->withAttribute('doc_version', $docVersion);
            $request = $request->withAttribute('doc_path', $matches[2] ?? '');
        }

        return $handler->handle($request);
    }
}
