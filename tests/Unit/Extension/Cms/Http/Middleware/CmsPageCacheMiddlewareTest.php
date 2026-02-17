<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Extension\Cms\Config\CmsCacheConfig;
use Pulsar\Extension\Cms\Http\Middleware\CmsPageCacheMiddleware;
use Pulsar\Http\Message\Response;

#[CoversClass(CmsPageCacheMiddleware::class)]
final class CmsPageCacheMiddlewareTest extends TestCase
{
    #[Test]
    public function different_query_strings_produce_different_cache_keys(): void
    {
        $spy = new CacheKeySpy();
        $middleware = new CmsPageCacheMiddleware($spy, $this->createCacheConfig());
        $handler = $this->createHandler();

        $middleware->process($this->createRequest('/articles', ['page' => '1']), $handler);
        $middleware->process($this->createRequest('/articles', ['page' => '2']), $handler);

        self::assertCount(2, $spy->keys);
        self::assertNotSame($spy->keys[0], $spy->keys[1], 'Different query params must produce different cache keys');
    }

    #[Test]
    public function utm_params_are_excluded_from_cache_key(): void
    {
        $spy = new CacheKeySpy();
        $middleware = new CmsPageCacheMiddleware($spy, $this->createCacheConfig());
        $handler = $this->createHandler();

        $middleware->process($this->createRequest('/articles', []), $handler);
        $middleware->process($this->createRequest('/articles', [
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'spring',
        ]), $handler);

        self::assertCount(2, $spy->keys);
        self::assertSame($spy->keys[0], $spy->keys[1], 'UTM params must not affect the cache key');
    }

    #[Test]
    public function nocache_param_bypasses_cache_entirely(): void
    {
        $cache = $this->createMock(TaggedCacheInterface::class);
        $cache->expects(self::never())->method('set');

        $middleware = new CmsPageCacheMiddleware($cache, $this->createCacheConfig());
        $handler = $this->createHandler();

        $middleware->process($this->createRequest('/articles', ['_nocache' => '1']), $handler);
    }

    #[Test]
    public function query_params_are_sorted_for_deterministic_keys(): void
    {
        $spy = new CacheKeySpy();
        $middleware = new CmsPageCacheMiddleware($spy, $this->createCacheConfig());
        $handler = $this->createHandler();

        $middleware->process($this->createRequest('/articles', ['page' => '1', 'sort' => 'date']), $handler);
        $middleware->process($this->createRequest('/articles', ['sort' => 'date', 'page' => '1']), $handler);

        self::assertCount(2, $spy->keys);
        self::assertSame($spy->keys[0], $spy->keys[1], 'Same params in different order must produce identical cache keys');
    }

    #[Test]
    #[DataProvider('excludedQueryParamPrefixesProvider')]
    public function tracking_params_are_excluded(string $paramName): void
    {
        $spy = new CacheKeySpy();
        $middleware = new CmsPageCacheMiddleware($spy, $this->createCacheConfig());
        $handler = $this->createHandler();

        $middleware->process($this->createRequest('/page', []), $handler);
        $middleware->process($this->createRequest('/page', [$paramName => 'value']), $handler);

        self::assertCount(2, $spy->keys);
        self::assertSame($spy->keys[0], $spy->keys[1]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function excludedQueryParamPrefixesProvider(): iterable
    {
        yield 'utm_source' => ['utm_source'];
        yield 'utm_medium' => ['utm_medium'];
        yield 'utm_campaign' => ['utm_campaign'];
        yield 'utm_content' => ['utm_content'];
        yield 'fbclid' => ['fbclid'];
        yield 'gclid' => ['gclid'];
        yield 'msclkid' => ['msclkid'];
    }

    /**
     * @param array<string, string> $queryParams
     */
    private function createRequest(string $path, array $queryParams): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn($path);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn($queryParams);
        $request->method('getHeaderLine')->willReturn('text/html');
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name, mixed $default = null): mixed => match ($name) {
                'identity' => null,
                'tenant_id' => 'default',
                'locale' => 'en',
                default => $default,
            },
        );

        return $request;
    }

    private function createHandler(): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(
            Response::html('<html><body>Content</body></html>'),
        );

        return $handler;
    }

    private function createCacheConfig(): CmsCacheConfig
    {
        return CmsCacheConfig::fromArray([]);
    }
}

/**
 * Simple spy implementation of TaggedCacheInterface that records cache keys on set().
 *
 * @internal Test double only
 */
final class CacheKeySpy implements TaggedCacheInterface
{
    /** @var list<string> */
    public array $keys = [];

    public function get(string $key): mixed
    {
        return null;
    }

    public function set(string $key, mixed $value, array $tags, ?int $ttlSeconds = null): bool
    {
        $this->keys[] = $key;

        return true;
    }

    public function delete(string $key): bool
    {
        return true;
    }

    public function invalidateTag(string $tag): void {}

    public function invalidateTags(array $tags): void {}
}
