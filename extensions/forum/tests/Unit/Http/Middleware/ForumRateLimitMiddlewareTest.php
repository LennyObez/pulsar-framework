<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
    private function makeHandler(): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(200));

        return $handler;
    }

    #[Test]
    #[DataProvider('readMethodProvider')]
    public function readRequestsPassThroughWithoutRateLimiting(string $method): void
    {
        $cache = $this->createStub(TaggedCacheInterface::class);
        $middleware = new ForumRateLimitMiddleware($cache);

        $request = new ServerRequest(method: $method, uri: '/forum');
        $handler = $this->makeHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
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
    public function writeRequestWithinLimitPassesThrough(): void
    {
        $cache = $this->createStub(TaggedCacheInterface::class);
        $cache->method('get')->willReturn(null); // no existing counter
        $cache->method('set')->willReturn(true);

        $middleware = new ForumRateLimitMiddleware($cache);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads',
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
        );

        $handler = $this->makeHandler();
        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->hasHeader('X-RateLimit-Limit'));
        self::assertTrue($response->hasHeader('X-RateLimit-Remaining'));
    }

    #[Test]
    public function writeRequestExceedingLimitReturns429(): void
    {
        // Simulate exceeding the 5/hr report limit
        $cache = $this->createStub(TaggedCacheInterface::class);
        $cache->method('get')->willReturn('5'); // already at limit
        $cache->method('set')->willReturn(true);

        $middleware = new ForumRateLimitMiddleware($cache);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/reports',
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );

        $handler = $this->makeHandler();
        $response = $middleware->process($request, $handler);

        self::assertSame(429, $response->getStatusCode());
        self::assertTrue($response->hasHeader('Retry-After'));
        self::assertSame('5', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('0', $response->getHeaderLine('X-RateLimit-Remaining'));

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Too Many Requests', $body['error']);
    }

    #[Test]
    #[DataProvider('pathToLimitProvider')]
    public function groupResolvesToCorrectLimit(string $path, string $expectedLimit): void
    {
        // Return count that exceeds any limit to trigger 429
        $cache = $this->createStub(TaggedCacheInterface::class);
        $cache->method('get')->willReturn('999');
        $cache->method('set')->willReturn(true);

        $middleware = new ForumRateLimitMiddleware($cache);

        $request = new ServerRequest(
            method: 'POST',
            uri: $path,
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );

        $handler = $this->makeHandler();
        $response = $middleware->process($request, $handler);

        self::assertSame(429, $response->getStatusCode());
        self::assertSame($expectedLimit, $response->getHeaderLine('X-RateLimit-Limit'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pathToLimitProvider(): iterable
    {
        yield 'reports API' => ['/api/v1/forum/reports', '5'];
        yield 'reports page' => ['/forum/reports', '5'];
        yield 'threads API' => ['/api/v1/forum/threads', '20'];
        yield 'threads page' => ['/forum/threads', '20'];
        yield 'posts API' => ['/api/v1/forum/posts', '30'];
        yield 'posts page' => ['/forum/posts', '30'];
        yield 'votes API' => ['/api/v1/forum/votes', '60'];
        yield 'votes page' => ['/forum/votes', '60'];
        yield 'unknown path' => ['/api/v1/forum/something-else', '120'];
    }

    #[Test]
    public function missingRemoteAddrUsesUnknownHash(): void
    {
        $cache = $this->createStub(TaggedCacheInterface::class);
        $cache->method('get')->willReturn(null);
        $cache->method('set')->willReturn(true);

        $middleware = new ForumRateLimitMiddleware($cache);

        // No REMOTE_ADDR in server params
        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads',
        );

        $handler = $this->makeHandler();
        $response = $middleware->process($request, $handler);

        // Should still work, using 'unknown' as the IP
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function remainingHeaderDecreasesWithCount(): void
    {
        // Counter at 3, limit for threads is 20 => remaining = 20 - 4 = 16
        $cache = $this->createStub(TaggedCacheInterface::class);
        $cache->method('get')->willReturn('3');
        $cache->method('set')->willReturn(true);

        $middleware = new ForumRateLimitMiddleware($cache);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads',
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
        );

        $handler = $this->makeHandler();
        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('20', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('16', $response->getHeaderLine('X-RateLimit-Remaining'));
    }
}
