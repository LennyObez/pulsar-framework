<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use {{namespace}}\Http\Middleware\AuthMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(AuthMiddleware::class)]
final class AuthMiddlewareTest extends TestCase
{
    #[Test]
    public function it_allows_health_check_without_auth(): void
    {
        $middleware = new AuthMiddleware();

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/health');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $expectedResponse = $this->createStub(ResponseInterface::class);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $response = $middleware->process($request, $handler);

        self::assertSame($expectedResponse, $response);
    }

    #[Test]
    public function it_rejects_unauthenticated_requests(): void
    {
        $middleware = new AuthMiddleware();

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/api/v1/users');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getHeaderLine')->willReturn('');

        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $middleware->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());
    }

    // TODO: Add tests for valid API key and valid Bearer token scenarios
}
