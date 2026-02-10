<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Http\Middleware\CmsPublicRateLimitMiddleware;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\ResponseStatus;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(CmsPublicRateLimitMiddleware::class)]
final class CmsPublicRateLimitMiddlewareTest extends TestCase
{
    private InMemoryTaggedCache $cache;

    protected function setUp(): void
    {
        $this->cache = new InMemoryTaggedCache();
    }

    #[Test]
    public function allowsRequestWithinContentLimit(): void
    {
        $config = new CmsConfig(publicRateLimitContent: 10);
        $middleware = new CmsPublicRateLimitMiddleware($this->cache, $config);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/blog/hello-world',
            serverParams: ['REMOTE_ADDR' => '192.168.1.1'],
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertSame('10', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('9', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    #[Test]
    public function rejectsContentRequestOverLimit(): void
    {
        $config = new CmsConfig(publicRateLimitContent: 2);
        $middleware = new CmsPublicRateLimitMiddleware($this->cache, $config);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/blog/hello-world',
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        // First two requests succeed
        $middleware->process($request, $handler);
        $middleware->process($request, $handler);

        // Third request is rate-limited
        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::TooManyRequests->value, $response->getStatusCode());
        self::assertNotEmpty($response->getHeaderLine('Retry-After'));
        self::assertSame('0', $response->getHeaderLine('X-RateLimit-Remaining'));

        /** @var array{error: string, retry_after: int} $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Too Many Requests', $body['error']);
        self::assertArrayHasKey('retry_after', $body);
    }

    #[Test]
    public function checkoutPathUsesCheckoutLimit(): void
    {
        $config = new CmsConfig(publicRateLimitContent: 100, publicRateLimitCheckout: 1);
        $middleware = new CmsPublicRateLimitMiddleware($this->cache, $config);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/checkout/submit',
            serverParams: ['REMOTE_ADDR' => '10.0.0.2'],
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        // First checkout request succeeds
        $first = $middleware->process($request, $handler);
        self::assertSame(ResponseStatus::OK->value, $first->getStatusCode());
        self::assertSame('1', $first->getHeaderLine('X-RateLimit-Limit'));

        // Second checkout request is rate-limited
        $second = $middleware->process($request, $handler);
        self::assertSame(ResponseStatus::TooManyRequests->value, $second->getStatusCode());
    }

    #[Test]
    public function contentAndCheckoutLimitsAreIndependent(): void
    {
        $config = new CmsConfig(publicRateLimitContent: 1, publicRateLimitCheckout: 1);
        $middleware = new CmsPublicRateLimitMiddleware($this->cache, $config);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $contentRequest = new ServerRequest(
            method: 'GET',
            uri: '/blog/post',
            serverParams: ['REMOTE_ADDR' => '10.0.0.3'],
        );

        $checkoutRequest = new ServerRequest(
            method: 'POST',
            uri: '/checkout/submit',
            serverParams: ['REMOTE_ADDR' => '10.0.0.3'],
        );

        // Exhaust content limit
        $middleware->process($contentRequest, $handler);

        // Checkout should still work (different group key)
        $response = $middleware->process($checkoutRequest, $handler);
        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function usesUnknownIpWhenNoRemoteAddr(): void
    {
        $config = new CmsConfig(publicRateLimitContent: 10);
        $middleware = new CmsPublicRateLimitMiddleware($this->cache, $config);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/page',
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertSame('10', $response->getHeaderLine('X-RateLimit-Limit'));
    }
}

/**
 * Minimal in-memory TaggedCacheInterface for testing.
 *
 * @internal Test-only
 */
final class InMemoryTaggedCache implements TaggedCacheInterface
{
    /** @var array<string, mixed> */
    private array $store = [];

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

    public function invalidateTag(string $tag): void {}

    public function invalidateTags(array $tags): void {}
}
