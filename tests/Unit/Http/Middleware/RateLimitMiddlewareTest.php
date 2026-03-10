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
use Pulsar\Http\RateLimit\RateLimitResult;
use Pulsar\Http\ResponseStatus;

#[CoversClass(RateLimitMiddleware::class)]
#[CoversClass(RateLimiter::class)]
#[CoversClass(RateLimitResult::class)]
final class RateLimitMiddlewareTest extends TestCase
{
    #[Test]
    public function allowsRequestWithinLimit(): void
    {
        $limiter = new RateLimiter(maxAttempts: 10, windowSeconds: 60);
        $middleware = new RateLimitMiddleware($limiter);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));
        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertSame('10', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('9', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    #[Test]
    public function rejectsRequestOverLimit(): void
    {
        $limiter = new RateLimiter(maxAttempts: 1, windowSeconds: 60);
        $middleware = new RateLimitMiddleware($limiter);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        // First request succeeds
        $middleware->process($request, $handler);

        // Second request is rate-limited
        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::TooManyRequests->value, $response->getStatusCode());
        self::assertNotEmpty($response->getHeaderLine('Retry-After'));
        self::assertSame('0', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    #[Test]
    public function returns429JsonBody(): void
    {
        $limiter = new RateLimiter(maxAttempts: 1, windowSeconds: 60);
        $middleware = new RateLimitMiddleware($limiter);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));
        $middleware->process($request, $handler);
        $response = $middleware->process($request, $handler);

        /** @var array{error: string, retry_after: int} $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Too Many Requests', $body['error']);
        self::assertArrayHasKey('retry_after', $body);
    }

    /**
     * F7.2: previously every request without REMOTE_ADDR landed in a
     * shared `'unknown'` bucket, effectively disabling the rate limit
     * (fail-open) under partial-info conditions. The fallback now
     * uses a User-Agent hash so distinct clients still get distinct
     * buckets — the limiter keeps working under partial info.
     */
    #[Test]
    public function distinctUserAgentsGetDistinctBucketsWithoutRemoteAddr(): void
    {
        $limiter = new RateLimiter(maxAttempts: 1, windowSeconds: 60);
        $middleware = new RateLimitMiddleware($limiter);

        $alice = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['User-Agent' => 'AliceBrowser/1.0'],
        );
        $bob = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['User-Agent' => 'BobBrowser/2.0'],
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $aliceResponse = $middleware->process($alice, $handler);
        $bobResponse = $middleware->process($bob, $handler);

        // Both clients pass on their first request — distinct buckets.
        self::assertSame(ResponseStatus::OK->value, $aliceResponse->getStatusCode());
        self::assertSame(ResponseStatus::OK->value, $bobResponse->getStatusCode());

        // A second hit on Alice's UA exceeds her limit; Bob remains
        // unaffected (proves the buckets do not collide).
        $aliceSecondResponse = $middleware->process($alice, $handler);
        self::assertSame(ResponseStatus::TooManyRequests->value, $aliceSecondResponse->getStatusCode());
    }

    #[Test]
    public function fallsBackToMethodUriHashWhenNoIpNoUa(): void
    {
        $limiter = new RateLimiter(maxAttempts: 1, windowSeconds: 60);
        $middleware = new RateLimitMiddleware($limiter);

        $request = new ServerRequest(method: 'GET', uri: '/');

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $first = $middleware->process($request, $handler);
        self::assertSame(ResponseStatus::OK->value, $first->getStatusCode());

        // Same method + URI lands in the same bucket → second is rejected.
        $second = $middleware->process($request, $handler);
        self::assertSame(ResponseStatus::TooManyRequests->value, $second->getStatusCode());
    }
}
