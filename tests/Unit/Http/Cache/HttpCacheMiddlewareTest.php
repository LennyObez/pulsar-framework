<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Cache\CachedResponse;
use Pulsar\Http\Cache\HttpCacheMiddleware;
use Pulsar\Http\Cache\InMemoryCacheStorage;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(HttpCacheMiddleware::class)]
#[CoversClass(InMemoryCacheStorage::class)]
#[CoversClass(CachedResponse::class)]
final class HttpCacheMiddlewareTest extends TestCase
{
    private InMemoryCacheStorage $storage;
    private HttpCacheMiddleware $middleware;

    protected function setUp(): void
    {
        $this->storage = new InMemoryCacheStorage();
        $this->middleware = new HttpCacheMiddleware($this->storage, defaultTtl: 60);
    }

    #[Test]
    public function cachesGetRequestsAndServesFromCache(): void
    {
        $handler = $this->createHandler(new Response(statusCode: 200, body: 'Hello'));

        $request = $this->createGetRequest('/api/test');

        // First request: MISS
        $response1 = $this->middleware->process($request, $handler);
        self::assertSame(200, $response1->getStatusCode());
        self::assertSame('MISS', $response1->getHeaderLine('X-Cache'));
        self::assertNotEmpty($response1->getHeaderLine('ETag'));

        // Second request: HIT
        $response2 = $this->middleware->process($request, $handler);
        self::assertSame(200, $response2->getStatusCode());
        self::assertSame('HIT', $response2->getHeaderLine('X-Cache'));
        self::assertSame('Hello', (string) $response2->getBody());
    }

    #[Test]
    public function doesNotCachePostRequests(): void
    {
        $handler = $this->createHandler(new Response(statusCode: 200, body: 'Created'));

        $request = $this->createRequest('POST', '/api/test');

        $response = $this->middleware->process($request, $handler);
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('X-Cache'));
    }

    #[Test]
    public function doesNotCacheNon2xxResponses(): void
    {
        $handler = $this->createHandler(new Response(statusCode: 404, body: 'Not Found'));

        $request = $this->createGetRequest('/api/missing');

        $response1 = $this->middleware->process($request, $handler);
        self::assertSame(404, $response1->getStatusCode());

        // Should not be cached — no X-Cache HIT on second request
        $response2 = $this->middleware->process($request, $handler);
        self::assertFalse($response2->hasHeader('X-Cache') && $response2->getHeaderLine('X-Cache') === 'HIT');
    }

    #[Test]
    public function respectsCacheDisabledAttribute(): void
    {
        $handler = $this->createHandler(new Response(statusCode: 200, body: 'Dynamic'));

        $request = $this->createGetRequest('/api/dynamic')
            ->withAttribute('cache.enabled', false);

        $response = $this->middleware->process($request, $handler);
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('X-Cache'));
    }

    #[Test]
    public function returns304WhenEtagMatches(): void
    {
        $handler = $this->createHandler(new Response(statusCode: 200, body: 'Cached Content'));

        $request = $this->createGetRequest('/api/data');

        // Populate cache
        $response = $this->middleware->process($request, $handler);
        $etag = $response->getHeaderLine('ETag');
        self::assertNotEmpty($etag);

        // Request with matching If-None-Match
        $conditionalRequest = $request->withHeader('If-None-Match', $etag);
        $notModified = $this->middleware->process($conditionalRequest, $handler);

        self::assertSame(304, $notModified->getStatusCode());
        self::assertSame('HIT', $notModified->getHeaderLine('X-Cache'));
    }

    #[Test]
    public function setCacheControlHeadersOnMiss(): void
    {
        $handler = $this->createHandler(new Response(statusCode: 200, body: 'Test'));

        $request = $this->createGetRequest('/api/public');
        $response = $this->middleware->process($request, $handler);

        $cacheControl = $response->getHeaderLine('Cache-Control');
        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('max-age=60', $cacheControl);
    }

    #[Test]
    public function respectsPrivateCacheAttribute(): void
    {
        $handler = $this->createHandler(new Response(statusCode: 200, body: 'Private'));

        $request = $this->createGetRequest('/api/private')
            ->withAttribute('cache.private', true);

        $response = $this->middleware->process($request, $handler);

        self::assertStringContainsString('private', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function respectsCustomTtlAttribute(): void
    {
        $handler = $this->createHandler(new Response(statusCode: 200, body: 'Custom'));

        $request = $this->createGetRequest('/api/custom')
            ->withAttribute('cache.ttl', 300);

        $response = $this->middleware->process($request, $handler);

        self::assertStringContainsString('max-age=300', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function tagBasedInvalidation(): void
    {
        $handler = $this->createHandler(new Response(statusCode: 200, body: 'Tagged'));

        $request = $this->createGetRequest('/api/tagged')
            ->withAttribute('cache.tags', ['users', 'page-1']);

        // Populate cache
        $this->middleware->process($request, $handler);

        // Invalidate by tag
        $this->middleware->invalidateByTags(['users']);

        // Should be a cache miss now
        $response = $this->middleware->process($request, $handler);
        self::assertSame('MISS', $response->getHeaderLine('X-Cache'));
    }

    #[Test]
    public function clearCacheRemovesAllEntries(): void
    {
        $handler = $this->createHandler(new Response(statusCode: 200, body: 'Content'));

        $this->middleware->process($this->createGetRequest('/a'), $handler);
        $this->middleware->process($this->createGetRequest('/b'), $handler);

        $this->middleware->clearCache();

        // Both should be misses now
        $r1 = $this->middleware->process($this->createGetRequest('/a'), $handler);
        $r2 = $this->middleware->process($this->createGetRequest('/b'), $handler);

        self::assertSame('MISS', $r1->getHeaderLine('X-Cache'));
        self::assertSame('MISS', $r2->getHeaderLine('X-Cache'));
    }

    #[Test]
    public function cachedResponseDetectsExpiration(): void
    {
        $expired = new CachedResponse(200, [], 'body', '"etag"', time() - 120, time() - 60);
        self::assertTrue($expired->isExpired());
        self::assertSame(0, $expired->remainingTtl());

        $fresh = new CachedResponse(200, [], 'body', '"etag"', time(), time() + 3600);
        self::assertFalse($fresh->isExpired());
        self::assertGreaterThan(0, $fresh->remainingTtl());
    }

    #[Test]
    public function inMemoryStorageEvictsOldestAtCapacity(): void
    {
        $storage = new InMemoryCacheStorage(maxEntries: 2);
        $now = time();

        $storage->set('k1', new CachedResponse(200, [], 'a', '"a"', $now, $now + 60), 60);
        $storage->set('k2', new CachedResponse(200, [], 'b', '"b"', $now, $now + 120), 120);
        $storage->set('k3', new CachedResponse(200, [], 'c', '"c"', $now, $now + 180), 180);

        // k1 had the earliest expiry, should be evicted
        self::assertNull($storage->get('k1'));
        self::assertNotNull($storage->get('k2'));
        self::assertNotNull($storage->get('k3'));
    }

    #[Test]
    public function inMemoryStorageHandlesTagInvalidation(): void
    {
        $storage = new InMemoryCacheStorage();
        $now = time();

        $storage->set('k1', new CachedResponse(200, [], 'a', '"a"', $now, $now + 60), 60, ['users']);
        $storage->set('k2', new CachedResponse(200, [], 'b', '"b"', $now, $now + 60), 60, ['posts']);
        $storage->set('k3', new CachedResponse(200, [], 'c', '"c"', $now, $now + 60), 60, ['users', 'posts']);

        $storage->invalidateByTags(['users']);

        self::assertNull($storage->get('k1'));
        self::assertNotNull($storage->get('k2'));
        self::assertNull($storage->get('k3'));
    }

    #[Test]
    public function respectsNoStoreDirectiveFromOrigin(): void
    {
        $response = new Response(
            statusCode: 200,
            headers: ['Cache-Control' => 'no-store'],
            body: 'Secret',
        );
        $handler = $this->createHandler($response);

        $request = $this->createGetRequest('/api/secret');
        $result = $this->middleware->process($request, $handler);

        // Should not add X-Cache header and should not cache
        $result2 = $this->middleware->process($request, $handler);
        self::assertFalse($result2->hasHeader('X-Cache') && $result2->getHeaderLine('X-Cache') === 'HIT');
    }

    #[Test]
    public function respectsNoStoreInMiddleOfCacheControlHeader(): void
    {
        // `no-store` is not required to be the first directive — `private,
        // no-store, max-age=0` is ordinary. A prefix match misses it and the
        // response gets cached, so the check must scan the whole header value.
        $response = new Response(
            statusCode: 200,
            headers: ['Cache-Control' => 'private, no-store, max-age=0'],
            body: 'Confidential',
        );
        $handler = $this->createHandler($response);

        $request = $this->createGetRequest('/api/confidential');

        // First request: should NOT be cached
        $this->middleware->process($request, $handler);

        // Second request: should still be a miss (not cached)
        $result2 = $this->middleware->process($request, $handler);
        self::assertFalse(
            $result2->hasHeader('X-Cache') && $result2->getHeaderLine('X-Cache') === 'HIT',
            'Response with "private, no-store" must not be cached',
        );
    }

    #[Test]
    public function respectsNoCacheInMiddleOfCacheControlHeader(): void
    {
        $response = new Response(
            statusCode: 200,
            headers: ['Cache-Control' => 'must-revalidate, no-cache'],
            body: 'Revalidate',
        );
        $handler = $this->createHandler($response);

        $request = $this->createGetRequest('/api/revalidate');

        $this->middleware->process($request, $handler);

        $result2 = $this->middleware->process($request, $handler);
        self::assertFalse(
            $result2->hasHeader('X-Cache') && $result2->getHeaderLine('X-Cache') === 'HIT',
            'Response with "must-revalidate, no-cache" must not be cached',
        );
    }

    private function createGetRequest(string $path): ServerRequestInterface
    {
        return $this->createRequest('GET', $path);
    }

    private function createRequest(string $method, string $path): ServerRequestInterface
    {
        return new ServerRequest(method: $method, uri: $path);
    }

    private function createHandler(ResponseInterface $response): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        return $handler;
    }
}
