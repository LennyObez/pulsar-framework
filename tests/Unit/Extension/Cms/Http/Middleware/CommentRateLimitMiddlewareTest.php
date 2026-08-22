<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Extension\Cms\Http\Middleware\CommentRateLimitMiddleware;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Tests\Benchmark\Cms\Support\InMemoryTaggedCache;

#[CoversClass(CommentRateLimitMiddleware::class)]
final class CommentRateLimitMiddlewareTest extends TestCase
{
    #[Test]
    public function processPassesThroughUnderLimit(): void
    {
        $cache = new InMemoryTaggedCache();
        $middleware = new CommentRateLimitMiddleware($cache, rateLimitPerMinute: 5, rateLimitPerHour: 30);

        $request = new ServerRequest(method: 'POST', uri: '/comments');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(201);
        $response->method('withHeader')->willReturn($response);
        $handler->method('handle')->willReturn($response);

        $result = $middleware->process($request, $handler);

        self::assertSame(201, $result->getStatusCode());
    }

    #[Test]
    public function processReturns429WhenPerMinuteLimitExceeded(): void
    {
        $cache = new InMemoryTaggedCache();
        $middleware = new CommentRateLimitMiddleware($cache, rateLimitPerMinute: 2, rateLimitPerHour: 100);

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(201);
        $response->method('withHeader')->willReturn($response);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $request = new ServerRequest(method: 'POST', uri: '/comments');

        // First two should pass
        $middleware->process($request, $handler);
        $middleware->process($request, $handler);

        // Third should be rate limited
        $result = $middleware->process($request, $handler);

        self::assertSame(429, $result->getStatusCode());
        $body = (string) $result->getBody();
        self::assertStringContainsString('Too Many Requests', $body);
    }

    #[Test]
    public function processReturns429WhenPerHourLimitExceeded(): void
    {
        $cache = new InMemoryTaggedCache();
        $middleware = new CommentRateLimitMiddleware($cache, rateLimitPerMinute: 100, rateLimitPerHour: 2);

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(201);
        $response->method('withHeader')->willReturn($response);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $request = new ServerRequest(method: 'POST', uri: '/comments');

        // First two pass
        $middleware->process($request, $handler);
        $middleware->process($request, $handler);

        // Third exceeds hourly limit
        $result = $middleware->process($request, $handler);

        self::assertSame(429, $result->getStatusCode());
    }

    #[Test]
    public function processIncludesRateLimitHeaders(): void
    {
        $cache = new InMemoryTaggedCache();
        $middleware = new CommentRateLimitMiddleware($cache, rateLimitPerMinute: 5, rateLimitPerHour: 30);

        $capturedHeaders = [];
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(201);
        $response->method('withHeader')->willReturnCallback(
            function (string $name, string $value) use (&$capturedHeaders, $response): ResponseInterface {
                $capturedHeaders[$name] = $value;
                return $response;
            },
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $request = new ServerRequest(method: 'POST', uri: '/comments');

        $middleware->process($request, $handler);

        self::assertArrayHasKey('X-RateLimit-Limit', $capturedHeaders);
        self::assertArrayHasKey('X-RateLimit-Remaining', $capturedHeaders);
        self::assertSame('5', $capturedHeaders['X-RateLimit-Limit']);
    }

    #[Test]
    public function processRetryAfterHeaderPresent(): void
    {
        $cache = new InMemoryTaggedCache();
        $middleware = new CommentRateLimitMiddleware($cache, rateLimitPerMinute: 1, rateLimitPerHour: 100);

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(201);
        $response->method('withHeader')->willReturn($response);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $request = new ServerRequest(method: 'POST', uri: '/comments');

        // First passes
        $middleware->process($request, $handler);

        // Second is rate limited — check the response has retry-after
        $result = $middleware->process($request, $handler);

        self::assertSame(429, $result->getStatusCode());
        $body = (string) $result->getBody();
        self::assertStringContainsString('retry_after', $body);
    }
}
