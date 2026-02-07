<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Throwable;

/**
 * Benchmark error handler middleware.
 *
 * Catches exceptions and returns a 500 JSON response.
 */
final class BenchErrorHandlerMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $e) {
            return Response::json(
                ['error' => $e->getMessage()],
                500,
            );
        }
    }
}
