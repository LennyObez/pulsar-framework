<?php

declare(strict_types=1);

namespace Pulsar\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Api;
use Pulsar\Http\Message\Response;

/**
 * Enforces request body size limits via the Content-Length header.
 *
 * Designed for PHP-FPM deployments where php.ini post_max_size may not
 * be set appropriately. Rejects requests with Content-Length exceeding
 * the configured maximum with a 413 Payload Too Large response.
 *
 * This middleware should be placed early in the pipeline, before body parsing.
 */
#[Api(since: '1.0.0')]
final readonly class BodySizeLimitMiddleware implements MiddlewareInterface
{
    private int $maxBytes;

    /**
     * @param int $maxSizeMb Maximum allowed body size in megabytes
     */
    public function __construct(int $maxSizeMb)
    {
        $this->maxBytes = $maxSizeMb * 1_048_576;
    }

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $contentLength = $request->getHeaderLine('Content-Length');

        if ($contentLength !== '') {
            $length = (int) $contentLength;

            if ($length > $this->maxBytes) {
                return new Response(413);
            }
        }

        return $handler->handle($request);
    }
}
