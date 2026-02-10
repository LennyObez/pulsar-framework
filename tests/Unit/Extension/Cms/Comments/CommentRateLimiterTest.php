<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Comments;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Extension\Cms\Http\Middleware\CommentRateLimitMiddleware;
use Pulsar\Http\Message\Response;

use function array_key_exists;
use function is_scalar;

#[CoversClass(CommentRateLimitMiddleware::class)]
final class CommentRateLimiterTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $cacheStore = [];

    private TaggedCacheInterface $cache;
    private RequestHandlerInterface $handler;

    protected function setUp(): void
    {
        $this->cacheStore = [];

        $this->cache = $this->createStub(TaggedCacheInterface::class);

        $this->cache->method('get')->willReturnCallback(
            fn(string $key): mixed => array_key_exists($key, $this->cacheStore) ? $this->cacheStore[$key] : null,
        );

        $this->cache->method('set')->willReturnCallback(
            function (string $key, mixed $value): bool {
                $this->cacheStore[$key] = is_scalar($value) ? (string) $value : '';

                return true;
            },
        );

        $this->handler = $this->createStub(RequestHandlerInterface::class);
        $this->handler->method('handle')->willReturn(
            Response::json(['ok' => true], 200),
        );
    }

    // -- Under limit: allowed ---------------------------------------------

    #[Test]
    public function firstRequestIsAllowed(): void
    {
        $middleware = new CommentRateLimitMiddleware($this->cache);
        $request = $this->createRequest('192.168.1.1');

        $response = $middleware->process($request, $this->handler);

        self::assertNotSame(429, $response->getStatusCode());
    }

    #[Test]
    public function underPerMinuteLimitIsAllowed(): void
    {
        $middleware = new CommentRateLimitMiddleware($this->cache, rateLimitPerMinute: 5);
        $request = $this->createRequest('10.0.0.1');

        // Send 5 requests (at the limit)
        for ($i = 0; $i < 5; $i++) {
            $response = $middleware->process($request, $this->handler);
            self::assertNotSame(429, $response->getStatusCode());
        }
    }

    // -- At per-minute limit: blocked -------------------------------------

    #[Test]
    public function exceedingPerMinuteLimitReturns429(): void
    {
        $middleware = new CommentRateLimitMiddleware($this->cache, rateLimitPerMinute: 3);
        $request = $this->createRequest('10.0.0.2');

        // Exhaust the per-minute limit
        for ($i = 0; $i < 3; $i++) {
            $middleware->process($request, $this->handler);
        }

        // 4th request should be rate-limited
        $response = $middleware->process($request, $this->handler);
        self::assertSame(429, $response->getStatusCode());
        self::assertTrue($response->hasHeader('Retry-After'));
        self::assertSame('0', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    // -- At per-hour limit: blocked ---------------------------------------

    #[Test]
    public function exceedingPerHourLimitReturns429(): void
    {
        // High per-minute, low per-hour to test hour logic
        $middleware = new CommentRateLimitMiddleware($this->cache, rateLimitPerMinute: 100, rateLimitPerHour: 5);
        $request = $this->createRequest('10.0.0.3');

        for ($i = 0; $i < 5; $i++) {
            $middleware->process($request, $this->handler);
        }

        // 6th request in the same hour window should be blocked
        $response = $middleware->process($request, $this->handler);
        self::assertSame(429, $response->getStatusCode());
    }

    // -- Different IPs have separate limits -------------------------------

    #[Test]
    public function differentIpsHaveSeparateLimits(): void
    {
        $middleware = new CommentRateLimitMiddleware($this->cache, rateLimitPerMinute: 2);

        $requestA = $this->createRequest('10.0.0.10');
        $requestB = $this->createRequest('10.0.0.20');

        // Exhaust limit for IP A
        for ($i = 0; $i < 2; $i++) {
            $middleware->process($requestA, $this->handler);
        }

        $responseA = $middleware->process($requestA, $this->handler);
        self::assertSame(429, $responseA->getStatusCode());

        // IP B should still be allowed
        $responseB = $middleware->process($requestB, $this->handler);
        self::assertNotSame(429, $responseB->getStatusCode());
    }

    // -- Rate limit headers on success ------------------------------------

    #[Test]
    public function successfulResponseIncludesRateLimitHeaders(): void
    {
        $middleware = new CommentRateLimitMiddleware($this->cache, rateLimitPerMinute: 10);
        $request = $this->createRequest('10.0.0.50');

        $response = $middleware->process($request, $this->handler);

        self::assertTrue($response->hasHeader('X-RateLimit-Limit'));
        self::assertTrue($response->hasHeader('X-RateLimit-Remaining'));
        self::assertSame('10', $response->getHeaderLine('X-RateLimit-Limit'));
    }

    // -- Missing IP defaults gracefully -----------------------------------

    #[Test]
    public function missingRemoteAddrDefaultsGracefully(): void
    {
        $middleware = new CommentRateLimitMiddleware($this->cache);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn([]);

        $response = $middleware->process($request, $this->handler);

        self::assertNotSame(429, $response->getStatusCode());
    }

    // -- Helper -----------------------------------------------------------

    private function createRequest(string $ip): ServerRequestInterface
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => $ip]);

        return $request;
    }
}
