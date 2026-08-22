<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\MiddlewareHandler;

#[CoversClass(MiddlewareHandler::class)]
final class MiddlewareHandlerTest extends TestCase
{
    #[Test]
    public function handleDelegatesToMiddlewareWithNextHandler(): void
    {
        $expectedResponse = new Response(statusCode: 201);

        $middleware = new class ($expectedResponse) implements MiddlewareInterface {
            public function __construct(private readonly ResponseInterface $response) {}

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->response;
            }
        };

        $nextHandler = $this->createStub(RequestHandlerInterface::class);

        $handler = new MiddlewareHandler($middleware, $nextHandler);
        $result = $handler->handle(new ServerRequest());

        self::assertSame($expectedResponse, $result);
    }

    #[Test]
    public function handlePassesNextHandlerToMiddleware(): void
    {
        $nextResponse = new Response(statusCode: 204);

        $middleware = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request);
            }
        };

        $nextHandler = $this->createStub(RequestHandlerInterface::class);
        $nextHandler->method('handle')->willReturn($nextResponse);

        $handler = new MiddlewareHandler($middleware, $nextHandler);
        $result = $handler->handle(new ServerRequest());

        self::assertSame($nextResponse, $result);
    }
}
