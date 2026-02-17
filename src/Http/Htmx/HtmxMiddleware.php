<?php

declare(strict_types=1);

namespace Pulsar\Http\Htmx;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Api;
use Pulsar\Http\Middleware\MiddlewareInterface;

/**
 * PSR-15 middleware that detects px-* hypermedia requests.
 *
 * Attaches an HtmxRequest instance to the request attributes and
 * adds Vary: PX-Request to the response so caches distinguish
 * full-page from fragment responses.
 */
#[Api(since: '1.0.0')]
final readonly class HtmxMiddleware implements MiddlewareInterface
{
    public function __construct() {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $htmxRequest = new HtmxRequest($request);
        $request = $request->withAttribute('htmx', $htmxRequest);

        $response = $handler->handle($request);

        if ($htmxRequest->isHtmx()) {
            $response = $response->withAddedHeader('Vary', 'PX-Request');
        }

        return $response;
    }
}
