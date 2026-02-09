<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psr7Bridge\Middleware;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface as Psr15MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Api;
use Pulsar\Extension\Psr7Bridge\Adapter\Psr7ToPulsarRequest;
use Pulsar\Extension\Psr7Bridge\Adapter\Psr7ToPulsarResponse;
use Pulsar\Extension\Psr7Bridge\Adapter\PulsarToPsr7Request;
use Pulsar\Extension\Psr7Bridge\Adapter\PulsarToPsr7Response;
use Pulsar\Http\Middleware\MiddlewareInterface as PulsarMiddlewareInterface;
use Pulsar\Http\Request;
use Pulsar\Http\Response;

/**
 * Adapts a PSR-15 MiddlewareInterface to work in the Pulsar middleware pipeline.
 *
 * This adapter bridges the gap between PSR-15 middleware and the Pulsar pipeline
 * by converting requests/responses at the boundary. The wrapped PSR-15 middleware
 * sees standard PSR-7 objects and a PSR-15 RequestHandlerInterface that delegates
 * to the Pulsar pipeline's next handler.
 */
#[Api(since: '1.0.0')]
final readonly class Psr15MiddlewareAdapter implements PulsarMiddlewareInterface
{
    private PulsarToPsr7Request $toPsr7Request;
    private PulsarToPsr7Response $toPsr7Response;
    private Psr7ToPulsarRequest $toPulsarRequest;
    private Psr7ToPulsarResponse $toPulsarResponse;

    public function __construct(
        private Psr15MiddlewareInterface $middleware,
    ) {
        $this->toPsr7Request = new PulsarToPsr7Request();
        $this->toPsr7Response = new PulsarToPsr7Response();
        $this->toPulsarRequest = new Psr7ToPulsarRequest();
        $this->toPulsarResponse = new Psr7ToPulsarResponse();
    }

    public function process(Request $request, callable $next): Response
    {
        $psrRequest = $this->toPsr7Request->convert($request);

        $toPulsarRequest = $this->toPulsarRequest;
        $toPsr7Response = $this->toPsr7Response;

        // Create a PSR-15 handler that bridges back into the Pulsar pipeline
        $handler = new readonly class ($next, $toPulsarRequest, $toPsr7Response) implements RequestHandlerInterface {
            /**
             * @param callable(Request): Response $next
             */
            public function __construct(
                private readonly mixed $next,
                private readonly Psr7ToPulsarRequest $toPulsarRequest,
                private readonly PulsarToPsr7Response $toPsr7Response,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $pulsarRequest = $this->toPulsarRequest->convert($request);
                $pulsarResponse = ($this->next)($pulsarRequest);

                return $this->toPsr7Response->convert($pulsarResponse);
            }
        };

        $psrResponse = $this->middleware->process($psrRequest, $handler);

        return $this->toPulsarResponse->convert($psrResponse);
    }
}
