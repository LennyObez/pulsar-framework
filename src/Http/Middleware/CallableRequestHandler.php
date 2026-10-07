<?php

declare(strict_types=1);

namespace Pulsar\Http\Middleware;

use Closure;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Api;

/**
 * Adapts a callable into a PSR-15 RequestHandler.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CallableRequestHandler implements RequestHandlerInterface
{
    /** @var Closure(ServerRequestInterface): ResponseInterface */
    private Closure $handler;

    /**
     * @param callable(ServerRequestInterface): ResponseInterface $handler
     */
    public function __construct(callable $handler)
    {
        $this->handler = $handler(...);
    }

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return ($this->handler)($request);
    }
}
