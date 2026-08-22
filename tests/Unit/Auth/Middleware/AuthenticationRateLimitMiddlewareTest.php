<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\Middleware\AuthenticationRateLimitMiddleware;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\RateLimit\RateLimiterInterface;
use Pulsar\Http\RateLimit\RateLimitResult;
use Pulsar\Http\ResponseStatus;
use Pulsar\Http\TrustedProxy;

#[CoversClass(AuthenticationRateLimitMiddleware::class)]
final class AuthenticationRateLimitMiddlewareTest extends TestCase
{
    private RateLimiterInterface&Stub $limiter;

    protected function setUp(): void
    {
        $this->limiter = $this->createStub(RateLimiterInterface::class);
    }

    #[Test]
    public function allows_request_when_under_limit(): void
    {
        $this->limiter->method('hit')->willReturn(
            new RateLimitResult(allowed: true, limit: 5, remaining: 4, retryAfter: 0),
        );

        $middleware = new AuthenticationRateLimitMiddleware($this->limiter);
        $request = new ServerRequest(
            method: 'POST',
            uri: '/login',
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(
            new Response(statusCode: ResponseStatus::OK->value, body: 'OK'),
        );

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertSame('5', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('4', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    #[Test]
    public function blocks_request_when_limit_exceeded(): void
    {
        $this->limiter->method('hit')->willReturn(
            new RateLimitResult(allowed: false, limit: 5, remaining: 0, retryAfter: 600),
        );

        $middleware = new AuthenticationRateLimitMiddleware($this->limiter);
        $request = new ServerRequest(
            method: 'POST',
            uri: '/login',
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::TooManyRequests->value, $response->getStatusCode());
        self::assertSame('600', $response->getHeaderLine('Retry-After'));
        self::assertSame('5', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('0', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    #[Test]
    public function response_body_contains_error_and_retry_after(): void
    {
        $this->limiter->method('hit')->willReturn(
            new RateLimitResult(allowed: false, limit: 5, remaining: 0, retryAfter: 900),
        );

        $middleware = new AuthenticationRateLimitMiddleware($this->limiter);
        $request = new ServerRequest(
            method: 'POST',
            uri: '/login',
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );

        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $middleware->process($request, $handler);

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame('Too many authentication attempts', $body['error']);
        self::assertSame(900, $body['retry_after']);
    }

    #[Test]
    public function uses_remote_addr_as_rate_limit_key(): void
    {
        $capturedKey = null;

        $limiter = $this->createMock(RateLimiterInterface::class);
        $limiter->expects(self::once())
            ->method('hit')
            ->willReturnCallback(function (string $key) use (&$capturedKey): RateLimitResult {
                $capturedKey = $key;

                return new RateLimitResult(allowed: true, limit: 5, remaining: 4, retryAfter: 0);
            });

        $middleware = new AuthenticationRateLimitMiddleware($limiter);
        $request = new ServerRequest(
            method: 'POST',
            uri: '/login',
            serverParams: ['REMOTE_ADDR' => '192.168.1.100'],
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: 200));

        $middleware->process($request, $handler);

        self::assertSame('auth_rate_limit:192.168.1.100', $capturedKey);
    }

    #[Test]
    public function uses_trusted_proxy_when_provided(): void
    {
        $capturedKey = null;

        $limiter = $this->createMock(RateLimiterInterface::class);
        $limiter->expects(self::once())
            ->method('hit')
            ->willReturnCallback(function (string $key) use (&$capturedKey): RateLimitResult {
                $capturedKey = $key;

                return new RateLimitResult(allowed: true, limit: 5, remaining: 4, retryAfter: 0);
            });

        $proxy = new TrustedProxy(['10.0.0.0/8']);
        $middleware = new AuthenticationRateLimitMiddleware($limiter, $proxy);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/login',
            headers: ['X-Forwarded-For' => '203.0.113.50, 10.0.0.1'],
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: 200));

        $middleware->process($request, $handler);

        self::assertSame('auth_rate_limit:203.0.113.50', $capturedKey);
    }

    #[Test]
    public function falls_back_to_unknown_when_no_remote_addr(): void
    {
        $capturedKey = null;

        $limiter = $this->createMock(RateLimiterInterface::class);
        $limiter->expects(self::once())
            ->method('hit')
            ->willReturnCallback(function (string $key) use (&$capturedKey): RateLimitResult {
                $capturedKey = $key;

                return new RateLimitResult(allowed: true, limit: 5, remaining: 4, retryAfter: 0);
            });

        $middleware = new AuthenticationRateLimitMiddleware($limiter);
        $request = new ServerRequest(
            method: 'POST',
            uri: '/login',
            serverParams: [],
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: 200));

        $middleware->process($request, $handler);

        self::assertSame('auth_rate_limit:unknown', $capturedKey);
    }

    #[Test]
    public function does_not_call_handler_when_rate_limited(): void
    {
        $this->limiter->method('hit')->willReturn(
            new RateLimitResult(allowed: false, limit: 5, remaining: 0, retryAfter: 300),
        );

        $middleware = new AuthenticationRateLimitMiddleware($this->limiter);
        $request = new ServerRequest(
            method: 'POST',
            uri: '/auth/token',
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $middleware->process($request, $handler);
    }

    #[Test]
    public function rate_limit_headers_present_on_allowed_response(): void
    {
        $this->limiter->method('hit')->willReturn(
            new RateLimitResult(allowed: true, limit: 5, remaining: 2, retryAfter: 0),
        );

        $middleware = new AuthenticationRateLimitMiddleware($this->limiter);
        $request = new ServerRequest(
            method: 'POST',
            uri: '/login',
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(
            new Response(statusCode: ResponseStatus::OK->value, body: 'OK'),
        );

        $response = $middleware->process($request, $handler);

        self::assertTrue($response->hasHeader('X-RateLimit-Limit'));
        self::assertTrue($response->hasHeader('X-RateLimit-Remaining'));
        self::assertSame('5', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('2', $response->getHeaderLine('X-RateLimit-Remaining'));
    }
}
