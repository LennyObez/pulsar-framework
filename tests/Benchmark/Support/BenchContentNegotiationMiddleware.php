<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Middleware\MiddlewareInterface;

/**
 * Benchmark content negotiation middleware.
 *
 * Sets the Content-Type to application/json on the response.
 */
final class BenchContentNegotiationMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
