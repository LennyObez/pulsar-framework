<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psr7Bridge\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface as Psr15MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Api;
use Pulsar\Http\Middleware\MiddlewareInterface as PulsarMiddlewareInterface;

/**
 * Adapts a PSR-15 MiddlewareInterface to work in the Pulsar middleware pipeline.
 *
 * @deprecated Since 1.0.0-rc.11. Pulsar's middleware pipeline is now PSR-15 native.
 *             PSR-15 middleware can be used directly without this adapter.
 */
#[Api(since: '1.0.0')]
final readonly class Psr15MiddlewareAdapter implements PulsarMiddlewareInterface
{
    public function __construct(
        private Psr15MiddlewareInterface $middleware,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->middleware->process($request, $handler);
    }
}
