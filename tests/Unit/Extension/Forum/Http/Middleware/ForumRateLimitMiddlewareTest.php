<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Extension\Forum\Http\Middleware\ForumRateLimitMiddleware;

#[CoversClass(ForumRateLimitMiddleware::class)]
final class ForumRateLimitMiddlewareTest extends TestCase
{
    private TaggedCacheInterface&MockObject $cache;
    private ForumRateLimitMiddleware $middleware;

    protected function setUp(): void
    {
        $this->cache = $this->createMock(TaggedCacheInterface::class);
        $this->middleware = new ForumRateLimitMiddleware(cache: $this->cache);
    }

    private function makeRequest(string $method = 'POST', string $path = '/api/v1/forum/threads', string $ip = '192.168.1.1'): ServerRequestInterface&Stub
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn($path);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getUri')->willReturn($uri);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => $ip]);

        return $request;
    }

    private function makeHandler(): RequestHandlerInterface&Stub
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        return $handler;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function readMethodProvider(): iterable
    {
        yield 'GET' => ['GET'];
        yield 'HEAD' => ['HEAD'];
        yield 'OPTIONS' => ['OPTIONS'];
    }

    #[Test]
    #[DataProvider('readMethodProvider')]
    public function readRequestsBypassRateLimit(string $method): void
    {
        $request = $this->makeRequest($method);
        $expectedResponse = $this->createStub(ResponseInterface::class);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $this->cache->expects(self::never())->method('get');

        $response = $this->middleware->process($request, $handler);

        self::assertSame($expectedResponse, $response);
    }

    #[Test]
    public function firstWriteRequestIsAllowed(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->cache->expects(self::once())->method('set');

        $request = $this->makeRequest('POST', '/api/v1/forum/threads');
        $handler = $this->makeHandler();

        $response = $this->middleware->process($request, $handler);

        self::assertNotSame(429, $response->getStatusCode());
    }

    #[Test]
    public function requestWithinLimitIsAllowed(): void
    {
        $this->cache->method('get')->willReturn('5');
        $this->cache->expects(self::once())->method('set');

        $request = $this->makeRequest('POST', '/api/v1/forum/threads');
        $handler = $this->makeHandler();

        $response = $this->middleware->process($request, $handler);

        self::assertNotSame(429, $response->getStatusCode());
    }

    #[Test]
    public function requestExceedingLimitReturns429(): void
    {
        $this->cache->method('get')->willReturn('20');
        $this->cache->expects(self::once())->method('set');

        $request = $this->makeRequest('POST', '/api/v1/forum/threads');
        $handler = $this->makeHandler();

        $response = $this->middleware->process($request, $handler);

        self::assertSame(429, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function groupLimitProvider(): iterable
    {
        yield 'reports: 5/hr' => ['/api/v1/forum/reports', 5];
        yield 'threads: 20/hr' => ['/api/v1/forum/threads', 20];
        yield 'posts: 30/hr' => ['/api/v1/forum/posts', 30];
        yield 'votes: 60/hr' => ['/api/v1/forum/votes', 60];
        yield 'default: 120/hr' => ['/api/v1/forum/other', 120];
        yield 'reports (non-api): 5/hr' => ['/forum/reports', 5];
        yield 'threads (non-api): 20/hr' => ['/forum/threads', 20];
        yield 'posts (non-api): 30/hr' => ['/forum/posts', 30];
        yield 'votes (non-api): 60/hr' => ['/forum/votes', 60];
    }

    #[Test]
    #[DataProvider('groupLimitProvider')]
    public function groupLimitsAreCorrectlyApplied(string $path, int $limit): void
    {
        $this->cache->method('get')->willReturn((string) $limit);
        $this->cache->expects(self::once())->method('set');

        $request = $this->makeRequest('POST', $path);
        $handler = $this->makeHandler();

        $response = $this->middleware->process($request, $handler);

        self::assertSame(429, $response->getStatusCode());
    }

    #[Test]
    public function requestJustUnderLimitPassesThrough(): void
    {
        $this->cache->method('get')->willReturn('19');
        $this->cache->expects(self::once())->method('set');

        $request = $this->makeRequest('POST', '/api/v1/forum/threads');
        $handler = $this->makeHandler();

        $response = $this->middleware->process($request, $handler);

        self::assertNotSame(429, $response->getStatusCode());
    }

    #[Test]
    public function rateLimitedResponseIncludesRetryAfterHeader(): void
    {
        $this->cache->method('get')->willReturn('20');
        $this->cache->expects(self::once())->method('set');

        $request = $this->makeRequest('POST', '/api/v1/forum/threads');
        $handler = $this->makeHandler();

        $response = $this->middleware->process($request, $handler);

        self::assertSame(429, $response->getStatusCode());
        self::assertTrue($response->hasHeader('Retry-After'));
        self::assertTrue($response->hasHeader('X-RateLimit-Limit'));
        self::assertSame('0', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    #[Test]
    public function unknownIpIsHandledGracefully(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/api/v1/forum/threads');
        $request->method('getUri')->willReturn($uri);
        $request->method('getServerParams')->willReturn([]);

        $this->cache->method('get')->willReturn(null);
        $this->cache->expects(self::once())->method('set');

        $handler = $this->makeHandler();

        $response = $this->middleware->process($request, $handler);

        self::assertNotSame(429, $response->getStatusCode());
    }
}
