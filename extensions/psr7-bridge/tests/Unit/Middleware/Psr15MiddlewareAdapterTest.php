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
use stdClass;

final class Psr15MiddlewareAdapterTest extends TestCase
{
    #[Test]
    public function implementsPulsarMiddlewareInterface(): void
    {
        $interfaces = class_implements(Psr15MiddlewareAdapter::class);

        self::assertContains(PulsarMiddlewareInterface::class, $interfaces);
    }

    #[Test]
    public function processDelegatesToInnerMiddleware(): void
    {
        $expectedResponse = new PsrResponse(201);

        $inner = new readonly class ($expectedResponse) implements Psr15MiddlewareInterface {
            public function __construct(private ResponseInterface $response) {}

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
        $captured = new stdClass();
        $captured->request = null;
        $captured->handler = null;

        $inner = new class ($captured) implements Psr15MiddlewareInterface {
            public function __construct(private readonly stdClass $captured) {}

            #[Override]
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $this->captured->request = $request;
                $this->captured->handler = $handler;
                return $handler->handle($request);
            }
        };

        $adapter = new Psr15MiddlewareAdapter($inner);
        $request = new ServerRequest('POST', '/submit');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new PsrResponse());

        $adapter->process($request, $handler);

        self::assertInstanceOf(ServerRequestInterface::class, $captured->request);
        self::assertSame('POST', $captured->request->getMethod());
        self::assertSame($handler, $captured->handler);
    }
}
