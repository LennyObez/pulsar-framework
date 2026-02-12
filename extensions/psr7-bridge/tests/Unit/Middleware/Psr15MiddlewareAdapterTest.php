<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psr7Bridge\Tests\Unit\Middleware;

use Nyholm\Psr7\Response as PsrResponse;
use Nyholm\Psr7\ServerRequest;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface as Psr15MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Extension\Psr7Bridge\Middleware\Psr15MiddlewareAdapter;
use Pulsar\Http\Middleware\MiddlewareInterface as PulsarMiddlewareInterface;

final class Psr15MiddlewareAdapterTest extends TestCase
{
    #[Test]
    public function implementsPulsarMiddlewareInterface(): void
    {
        $inner = $this->createStub(Psr15MiddlewareInterface::class);
        $adapter = new Psr15MiddlewareAdapter($inner);

        self::assertInstanceOf(PulsarMiddlewareInterface::class, $adapter);
    }

    #[Test]
    public function processDelegatesToInnerMiddleware(): void
    {
        $expectedResponse = new PsrResponse(201);

        $inner = new class ($expectedResponse) implements Psr15MiddlewareInterface {
            public function __construct(private readonly ResponseInterface $response) {}

            #[Override]
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->response;
            }
        };

        $adapter = new Psr15MiddlewareAdapter($inner);
        $request = new ServerRequest('GET', '/');
        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $adapter->process($request, $handler);

        self::assertSame(201, $response->getStatusCode());
    }

    #[Test]
    public function processPassesRequestAndHandlerThrough(): void
    {
        $capturedRequest = null;
        $capturedHandler = null;

        $inner = new class ($capturedRequest, $capturedHandler) implements Psr15MiddlewareInterface {
            public function __construct(
                private ?ServerRequestInterface &$capturedRequest,
                private ?RequestHandlerInterface &$capturedHandler,
            ) {}

            #[Override]
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $this->capturedRequest = $request;
                $this->capturedHandler = $handler;
                return $handler->handle($request);
            }
        };

        $adapter = new Psr15MiddlewareAdapter($inner);
        $request = new ServerRequest('POST', '/submit');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new PsrResponse());

        $adapter->process($request, $handler);

        self::assertSame('POST', $capturedRequest?->getMethod());
        self::assertSame($handler, $capturedHandler);
    }
}
