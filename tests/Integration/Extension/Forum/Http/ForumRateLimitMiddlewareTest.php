<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Forum\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Extension\Forum\Http\Middleware\ForumRateLimitMiddleware;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(ForumRateLimitMiddleware::class)]
final class ForumRateLimitMiddlewareTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $cacheStore = [];

    #[Test]
    public function getRequestBypassesRateLimit(): void
    {
        $cache = $this->createInMemoryCache();
        $middleware = new ForumRateLimitMiddleware($cache);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/forum/threads');
        $handler = $this->createPassthroughHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('X-RateLimit-Limit'));
    }

    #[Test]
    public function headRequestBypassesRateLimit(): void
    {
        $cache = $this->createInMemoryCache();
        $middleware = new ForumRateLimitMiddleware($cache);

        $request = new ServerRequest(method: 'HEAD', uri: '/api/v1/forum/threads');
        $handler = $this->createPassthroughHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function postRequestAddsRateLimitHeaders(): void
    {
        $cache = $this->createInMemoryCache();
        $middleware = new ForumRateLimitMiddleware($cache);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads',
            serverParams: ['REMOTE_ADDR' => '192.168.1.1'],
        );
        $handler = $this->createPassthroughHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->hasHeader('X-RateLimit-Limit'));
        self::assertTrue($response->hasHeader('X-RateLimit-Remaining'));
        self::assertSame('20', $response->getHeaderLine('X-RateLimit-Limit'));
    }

    #[Test]
    public function reportPathUsesReportLimit(): void
    {
        $cache = $this->createInMemoryCache();
        $middleware = new ForumRateLimitMiddleware($cache);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/reports/new',
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );
        $handler = $this->createPassthroughHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame('5', $response->getHeaderLine('X-RateLimit-Limit'));
    }

    #[Test]
    public function postPathUsesPostLimit(): void
    {
        $cache = $this->createInMemoryCache();
        $middleware = new ForumRateLimitMiddleware($cache);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/posts/new',
            serverParams: ['REMOTE_ADDR' => '10.0.0.2'],
        );
        $handler = $this->createPassthroughHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame('30', $response->getHeaderLine('X-RateLimit-Limit'));
    }

    #[Test]
    public function votesPathUsesVoteLimit(): void
    {
        $cache = $this->createInMemoryCache();
        $middleware = new ForumRateLimitMiddleware($cache);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/votes/cast',
            serverParams: ['REMOTE_ADDR' => '10.0.0.3'],
        );
        $handler = $this->createPassthroughHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame('60', $response->getHeaderLine('X-RateLimit-Limit'));
    }

    #[Test]
    public function unknownPathUsesDefaultLimit(): void
    {
        $cache = $this->createInMemoryCache();
        $middleware = new ForumRateLimitMiddleware($cache);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/other',
            serverParams: ['REMOTE_ADDR' => '10.0.0.4'],
        );
        $handler = $this->createPassthroughHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame('120', $response->getHeaderLine('X-RateLimit-Limit'));
    }

    #[Test]
    public function rateLimitExceededReturns429(): void
    {
        $this->cacheStore = [];
        $cache = $this->createInMemoryCache();
        $middleware = new ForumRateLimitMiddleware($cache);
        $handler = $this->createPassthroughHandler();

        // Exhaust the report limit (5 per hour)
        for ($i = 0; $i < 5; $i++) {
            $request = new ServerRequest(
                method: 'POST',
                uri: '/api/v1/forum/reports/new',
                serverParams: ['REMOTE_ADDR' => '10.0.0.5'],
            );
            $response = $middleware->process($request, $handler);
            self::assertSame(200, $response->getStatusCode());
        }

        // 6th request should be rate limited
        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/reports/new',
            serverParams: ['REMOTE_ADDR' => '10.0.0.5'],
        );
        $response = $middleware->process($request, $handler);

        self::assertSame(429, $response->getStatusCode());
        self::assertTrue($response->hasHeader('Retry-After'));
        self::assertSame('0', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    #[Test]
    public function differentIpsHaveSeparateLimits(): void
    {
        $this->cacheStore = [];
        $cache = $this->createInMemoryCache();
        $middleware = new ForumRateLimitMiddleware($cache);
        $handler = $this->createPassthroughHandler();

        // Exhaust limit for IP A
        for ($i = 0; $i < 5; $i++) {
            $request = new ServerRequest(
                method: 'POST',
                uri: '/api/v1/forum/reports/new',
                serverParams: ['REMOTE_ADDR' => '10.0.0.10'],
            );
            $middleware->process($request, $handler);
        }

        // IP B should still have quota
        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/reports/new',
            serverParams: ['REMOTE_ADDR' => '10.0.0.11'],
        );
        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function remainingCountDecrementsCorrectly(): void
    {
        $this->cacheStore = [];
        $cache = $this->createInMemoryCache();
        $middleware = new ForumRateLimitMiddleware($cache);
        $handler = $this->createPassthroughHandler();

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/reports/new',
            serverParams: ['REMOTE_ADDR' => '10.0.0.20'],
        );

        $response1 = $middleware->process($request, $handler);
        self::assertSame('4', $response1->getHeaderLine('X-RateLimit-Remaining'));

        $response2 = $middleware->process($request, $handler);
        self::assertSame('3', $response2->getHeaderLine('X-RateLimit-Remaining'));
    }

    private function createInMemoryCache(): TaggedCacheInterface
    {
        $store = &$this->cacheStore;

        return new class ($store) implements TaggedCacheInterface {
            /** @param array<string, mixed> $store */
            public function __construct(private array &$store) {}

            public function get(string $key): mixed
            {
                return $this->store[$key] ?? null;
            }

            public function set(string $key, mixed $value, array $tags, ?int $ttlSeconds = null): bool
            {
                $this->store[$key] = $value;

                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->store[$key]);

                return true;
            }

            public function invalidateTag(string $tag): void
            {
                $this->store = [];
            }

            public function invalidateTags(array $tags): void
            {
                $this->store = [];
            }
        };
    }

    private function createPassthroughHandler(): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(200));

        return $handler;
    }
}
