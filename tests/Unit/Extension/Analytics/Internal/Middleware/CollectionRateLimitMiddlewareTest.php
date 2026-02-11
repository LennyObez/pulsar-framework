<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Analytics\Internal\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Cache\Application\Driver\CacheDriverInterface;
use Pulsar\Extension\Analytics\Config\AnalyticsConfig;
use Pulsar\Extension\Analytics\Config\RateLimitConfig;
use Pulsar\Extension\Analytics\Internal\Middleware\CollectionRateLimitMiddleware;
use Pulsar\Http\Message\Response;

/**
 * Extended cache driver interface for testing.
 *
 * The production middleware calls expire() which is not declared on
 * CacheDriverInterface. This local interface adds it so PHPUnit can
 * create stubs/mocks that support the method.
 */
interface TestCacheDriverInterface extends CacheDriverInterface
{
    public function expire(string $key, int $ttlSeconds): bool;
}

#[CoversClass(CollectionRateLimitMiddleware::class)]
final class CollectionRateLimitMiddlewareTest extends TestCase
{
    private ServerRequestInterface&Stub $request;
    private RequestHandlerInterface&Stub $handler;
    private ResponseInterface $normalResponse;

    protected function setUp(): void
    {
        $this->request = $this->createStub(ServerRequestInterface::class);
        $this->request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '192.168.1.100']);
        $this->request->method('getHeaderLine')->willReturn('');

        $this->handler = $this->createStub(RequestHandlerInterface::class);
        $this->normalResponse = Response::json(['ok' => true]);
        $this->handler->method('handle')->willReturn($this->normalResponse);
    }

    #[Test]
    public function passesThroughWhenNoCacheAvailable(): void
    {
        $config = new AnalyticsConfig();
        $middleware = new CollectionRateLimitMiddleware($config, null);

        $response = $middleware->process($this->request, $this->handler);

        self::assertSame($this->normalResponse, $response);
    }

    #[Test]
    public function allowsFirstRequest(): void
    {
        $cache = $this->createStub(TestCacheDriverInterface::class);
        $cache->method('get')->willReturn(null);

        $config = new AnalyticsConfig();
        $middleware = new CollectionRateLimitMiddleware($config, $cache);

        $response = $middleware->process($this->request, $this->handler);

        self::assertSame($this->normalResponse, $response);
    }

    #[Test]
    public function allowsRequestUnderLimit(): void
    {
        $cache = $this->createStub(TestCacheDriverInterface::class);
        $cache->method('get')->willReturn('10');

        $config = new AnalyticsConfig();
        $middleware = new CollectionRateLimitMiddleware($config, $cache);

        $response = $middleware->process($this->request, $this->handler);

        self::assertSame($this->normalResponse, $response);
    }

    #[Test]
    public function returns204WhenRateLimitExceeded(): void
    {
        $cache = $this->createStub(TestCacheDriverInterface::class);
        $cache->method('get')->willReturn('100');

        $config = new AnalyticsConfig(rateLimit: new RateLimitConfig(maxEventsPerIpPerMinute: 30, burst: 5));
        $middleware = new CollectionRateLimitMiddleware($config, $cache);

        $response = $middleware->process($this->request, $this->handler);

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function trustsXForwardedForFromTrustedProxy(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '10.0.0.1']);
        $request->method('getHeaderLine')->willReturn('203.0.113.50, 10.0.0.1');

        $cache = $this->createMock(TestCacheDriverInterface::class);
        $cache->method('get')->willReturn(null);
        // First request (current=0) uses set(), not increment()
        $cache->expects(self::once())->method('set')
            ->with(self::stringContains('analytics:rate:'), '1', 60);

        $config = new AnalyticsConfig(trustedProxies: ['10.0.0.1']);
        $middleware = new CollectionRateLimitMiddleware($config, $cache);

        $middleware->process($request, $this->handler);
    }

    #[Test]
    public function ignoresXForwardedForFromUntrustedSource(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '192.168.1.100']);
        $request->method('getHeaderLine')->willReturn('203.0.113.50');

        $cache = $this->createMock(TestCacheDriverInterface::class);
        $cache->method('get')->willReturn(null);
        // First request (current=0) uses set(), not increment()
        $cache->expects(self::once())->method('set')
            ->with(self::stringContains('analytics:rate:'), '1', 60);

        $config = new AnalyticsConfig(trustedProxies: ['10.0.0.1']);
        $middleware = new CollectionRateLimitMiddleware($config, $cache);

        $middleware->process($request, $this->handler);
    }

    #[Test]
    public function incrementsExistingCounter(): void
    {
        $cache = $this->createMock(TestCacheDriverInterface::class);
        $cache->method('get')->willReturn('5');
        $cache->expects(self::once())->method('increment');

        $config = new AnalyticsConfig();
        $middleware = new CollectionRateLimitMiddleware($config, $cache);

        $middleware->process($this->request, $this->handler);
    }
}
