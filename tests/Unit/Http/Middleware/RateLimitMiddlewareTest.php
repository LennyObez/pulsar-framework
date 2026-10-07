<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\RateLimitMiddleware;
use Pulsar\Http\RateLimit\RateLimiter;
use Pulsar\Http\RateLimit\RateLimitKeyStrategy;
use Pulsar\Http\RateLimit\RateLimitResult;
use Pulsar\Http\ResponseStatus;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;

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

    /**
     * One route, many ids, one bucket.
     *
     * The route-scoped strategies used to read `_route` and accept it only
     * `if (is_string($route))`. The attribute holds a {@see MatchedRoute}
     * object, so that test was false on every request that ever reached this
     * middleware and every route-scoped key silently degraded to method + path.
     * `/users/1` and `/users/2` were different buckets: a route-scoped limit was
     * evaded by varying the id, and the number of buckets the limiter store held
     * was bounded by the URL space rather than by the route table.
     */
    #[Test]
    public function routeStrategyBucketsEveryIdOfOneRouteTogether(): void
    {
        $limiter = new RateLimiter(maxAttempts: 1, windowSeconds: 60);
        $route = new Route([Method::GET], '/users/{user}', UsersProbeController::class, 'users.show');

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $first = new RateLimitMiddleware($limiter, null, RateLimitKeyStrategy::Route)
            ->forDispatchedRoute(new MatchedRoute($route, ['user' => '1']));
        $second = new RateLimitMiddleware($limiter, null, RateLimitKeyStrategy::Route)
            ->forDispatchedRoute(new MatchedRoute($route, ['user' => '2']));

        self::assertSame(
            ResponseStatus::OK->value,
            $first->process(new ServerRequest(method: 'GET', uri: '/users/1'), $handler)->getStatusCode(),
        );
        self::assertSame(
            ResponseStatus::TooManyRequests->value,
            $second->process(new ServerRequest(method: 'GET', uri: '/users/2'), $handler)->getStatusCode(),
            'A different id on the same route must not be a different bucket.',
        );
    }

    /**
     * The route cannot be substituted by a frame in front of this one.
     *
     * This middleware is piped route-level, so other route middleware runs
     * before it and can hand it any `_route` it likes. Reading the attribute
     * would make a security control's bucket the caller's to choose; the route
     * arrives as an argument instead.
     */
    #[Test]
    public function aRewrittenRouteAttributeDoesNotChangeTheBucket(): void
    {
        $limiter = new RateLimiter(maxAttempts: 1, windowSeconds: 60);
        $route = new Route([Method::GET], '/users/{user}', UsersProbeController::class, 'users.show');
        $decoy = new Route([Method::GET], '/elsewhere', UsersProbeController::class, 'elsewhere');

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $middleware = new RateLimitMiddleware($limiter, null, RateLimitKeyStrategy::Route)
            ->forDispatchedRoute(new MatchedRoute($route, ['user' => '1']));

        $spent = $middleware->process(new ServerRequest(method: 'GET', uri: '/users/1'), $handler);
        self::assertSame(ResponseStatus::OK->value, $spent->getStatusCode());

        $withForgedAttribute = new ServerRequest(method: 'GET', uri: '/users/1')
            ->withAttribute('_route', new MatchedRoute($decoy));

        self::assertSame(
            ResponseStatus::TooManyRequests->value,
            $middleware->process($withForgedAttribute, $handler)->getStatusCode(),
            'The attribute names another route; the bucket is still the dispatched one.',
        );
    }

    /**
     * An unbound copy keeps the documented per-URL fallback.
     *
     * Piped globally there is no route yet, and inventing one would be a guess.
     */
    #[Test]
    public function anUnboundCopyStillBucketsPerMethodAndPath(): void
    {
        $limiter = new RateLimiter(maxAttempts: 1, windowSeconds: 60);
        $middleware = new RateLimitMiddleware($limiter, null, RateLimitKeyStrategy::Route);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        self::assertSame(
            ResponseStatus::OK->value,
            $middleware->process(new ServerRequest(method: 'GET', uri: '/a'), $handler)->getStatusCode(),
        );
        self::assertSame(
            ResponseStatus::OK->value,
            $middleware->process(new ServerRequest(method: 'GET', uri: '/b'), $handler)->getStatusCode(),
        );
        self::assertSame(
            ResponseStatus::TooManyRequests->value,
            $middleware->process(new ServerRequest(method: 'GET', uri: '/a'), $handler)->getStatusCode(),
        );
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

/** A handler the probe routes can name. Never invoked. */
final class UsersProbeController
{
    public function __invoke(): void {}
}
