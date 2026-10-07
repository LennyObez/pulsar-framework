<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use {{namespace}}\Http\Middleware\RateLimitMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(RateLimitMiddleware::class)]
final class RateLimitMiddlewareTest extends TestCase
{
    #[Test]
    public function it_allows_requests_within_limit(): void
    {
        $middleware = new RateLimitMiddleware();

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('');
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '127.0.0.1']);

        $expectedResponse = $this->createStub(ResponseInterface::class);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $response = $middleware->process($request, $handler);

        self::assertSame($expectedResponse, $response);
    }

    // TODO: Add tests for rate limit exceeded scenario
}
