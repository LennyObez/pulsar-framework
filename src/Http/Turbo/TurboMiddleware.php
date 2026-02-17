<?php

declare(strict_types=1);

namespace Pulsar\Http\Turbo;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Api;
use Pulsar\Http\Middleware\MiddlewareInterface;

/**
 * PSR-15 middleware for Turbo Frame and Stream detection.
 *
 * Detects Turbo Frame requests (Turbo-Frame header) and attaches
 * the frame ID to request attributes so controllers can return
 * targeted frame content instead of full pages.
 */
#[Api(since: '1.0.0')]
final readonly class TurboMiddleware implements MiddlewareInterface
{
    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $frameId = $request->getHeaderLine('Turbo-Frame');
        $isTurboFrame = $frameId !== '';

        $accepts = $request->getHeaderLine('Accept');
        $acceptsStream = str_contains($accepts, 'text/vnd.turbo-stream.html');

        $request = $request
            ->withAttribute('turbo_frame', $isTurboFrame ? $frameId : null)
            ->withAttribute('turbo_stream', $acceptsStream);

        $response = $handler->handle($request);

        if ($isTurboFrame) {
            $response = $response->withAddedHeader('Vary', 'Turbo-Frame');
        }

        return $response;
    }
}
