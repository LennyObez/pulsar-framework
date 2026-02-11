<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\RateLimitMiddleware;
use Pulsar\Http\RateLimit\RateLimiter;
use Pulsar\Http\TrustedProxy;

#[CoversClass(RateLimitMiddleware::class)]
final class RateLimitMiddlewareCoverageTest extends TestCase
{
    #[Test]
    public function resolveKeyUsesTrustedProxyWhenProvided(): void
    {
        $limiter = new RateLimiter(maxAttempts: 10, windowSeconds: 60);
        $proxy = new TrustedProxy(['127.0.0.1/32']);

        $middleware = new RateLimitMiddleware($limiter, $proxy);

        // Request from trusted proxy with XFF header
        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['X-Forwarded-For' => '203.0.113.50'],
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('9', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    #[Test]
    public function resolveKeyUsesRemoteAddrWhenNoTrustedProxy(): void
    {
        $limiter = new RateLimiter(maxAttempts: 10, windowSeconds: 60);
        $middleware = new RateLimitMiddleware($limiter);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        // Hit twice with same IP
        $middleware->process($request, $handler);
        $response = $middleware->process($request, $handler);

        self::assertSame('8', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    #[Test]
    public function nonStringRemoteAddrUsesUnknownKey(): void
    {
        $limiter = new RateLimiter(maxAttempts: 2, windowSeconds: 60);
        $middleware = new RateLimitMiddleware($limiter);

        // No REMOTE_ADDR at all
        $request = new ServerRequest(method: 'GET', uri: '/');

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        // First and second use "unknown" key
        $middleware->process($request, $handler);
        $middleware->process($request, $handler);
        $response = $middleware->process($request, $handler);

        self::assertSame(429, $response->getStatusCode());
    }
}
