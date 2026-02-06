<?php

declare(strict_types=1);

namespace Pulsar\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface as PsrMiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;

/**
 * Wraps a PSR-15 middleware + next handler into a single RequestHandler.
 *
 * Used internally by MiddlewarePipeline to build the handler chain.
 */
#[Internal]
final readonly class MiddlewareHandler implements RequestHandlerInterface
{
    public function __construct(
        private PsrMiddlewareInterface $middleware,
        private RequestHandlerInterface $next,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->middleware->process($request, $this->next);
    }
}
