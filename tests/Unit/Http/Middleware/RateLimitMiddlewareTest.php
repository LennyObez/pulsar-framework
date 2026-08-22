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
use Pulsar\Http\RateLimit\RateLimitKeyStrategy;
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
     * A request without REMOTE_ADDR must not land in one shared
     * bucket. A single `'unknown'` bucket is fail-open: it disables
     * the rate limit precisely when the identifying information is
     * scarce. The fallback hashes the User-Agent instead, so
     * distinct clients keep distinct buckets under partial info.
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
    public function routeStrategyBucketsAllClientsTogetherPerRoute(): void
    {
        // The Route strategy caps total load on an endpoint: distinct clients
        // share one bucket per route.
        $limiter = new RateLimiter(maxAttempts: 1, windowSeconds: 60);
        $middleware = new RateLimitMiddleware($limiter, null, RateLimitKeyStrategy::Route);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $clientA = new ServerRequest(method: 'GET', uri: '/search', serverParams: ['REMOTE_ADDR' => '1.1.1.1']);
        $clientB = new ServerRequest(method: 'GET', uri: '/search', serverParams: ['REMOTE_ADDR' => '2.2.2.2']);

        self::assertSame(ResponseStatus::OK->value, $middleware->process($clientA, $handler)->getStatusCode());
        // Different client, same route → shared bucket is already spent.
        self::assertSame(ResponseStatus::TooManyRequests->value, $middleware->process($clientB, $handler)->getStatusCode());
    }

    #[Test]
    public function ipAndRouteStrategyIsolatesPerClientPerRoute(): void
    {
        $limiter = new RateLimiter(maxAttempts: 1, windowSeconds: 60);
        $middleware = new RateLimitMiddleware($limiter, null, RateLimitKeyStrategy::IpAndRoute);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $routeA = new ServerRequest(method: 'GET', uri: '/a', serverParams: ['REMOTE_ADDR' => '1.1.1.1']);
        $routeB = new ServerRequest(method: 'GET', uri: '/b', serverParams: ['REMOTE_ADDR' => '1.1.1.1']);

        // Same client, different routes → independent budgets.
        self::assertSame(ResponseStatus::OK->value, $middleware->process($routeA, $handler)->getStatusCode());
        self::assertSame(ResponseStatus::OK->value, $middleware->process($routeB, $handler)->getStatusCode());

        // Same client, same route again → that route's budget is spent.
        self::assertSame(ResponseStatus::TooManyRequests->value, $middleware->process($routeA, $handler)->getStatusCode());
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
