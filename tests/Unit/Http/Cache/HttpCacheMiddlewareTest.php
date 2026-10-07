<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Cache;

use Generator;
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
use Pulsar\Http\Response\StreamedResponse;

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

    #[Test]
    public function doesNotServeOneCallersResponseToAnother(): void
    {
        // The defect this class was found with: the key is method + path +
        // query, so an entry produced for a signed-in caller was handed to the
        // next requester of that URL — cross-account disclosure inside a
        // middleware an application enables expecting a speedup.
        $handler = $this->createEchoingHandler();

        $alice = $this->createGetRequest('/account')->withHeader('Cookie', 'PULSARSESSID=alice');
        $first = $this->middleware->process($alice, $handler);
        self::assertSame('account of [PULSARSESSID=alice]', (string) $first->getBody());

        $anonymous = $this->createGetRequest('/account');
        $second = $this->middleware->process($anonymous, $handler);

        self::assertSame('account of []', (string) $second->getBody());
        self::assertNotSame('HIT', $second->getHeaderLine('X-Cache'));
    }

    #[Test]
    public function doesNotServeAnAuthorizationBearingResponseToAnother(): void
    {
        $handler = $this->createEchoingHandler();

        $bearer = $this->createGetRequest('/api/me')->withHeader('Authorization', 'Bearer alice-token');
        $first = $this->middleware->process($bearer, $handler);
        self::assertSame('account of []', (string) $first->getBody());
        self::assertSame('BYPASS', $first->getHeaderLine('X-Cache'));

        $other = $this->createGetRequest('/api/me')->withHeader('Authorization', 'Bearer bob-token');
        $second = $this->middleware->process($other, $handler);

        self::assertNotSame('HIT', $second->getHeaderLine('X-Cache'));
    }

    #[Test]
    public function doesNotServeAStoredResponseToACallerCarryingIdentity(): void
    {
        // The same defect from the other side: an anonymous page handed to a
        // signed-in caller. The middleware cannot tell which of the two a hit
        // would be, so a caller-bound request never reads the shared store.
        $handler = $this->createHandler(new Response(statusCode: 200, body: 'anonymous page'));

        $this->middleware->process($this->createGetRequest('/page'), $handler);

        $signedIn = $this->createGetRequest('/page')->withHeader('Cookie', 'PULSARSESSID=bob');
        $response = $this->middleware->process($signedIn, $handler);

        self::assertSame('BYPASS', $response->getHeaderLine('X-Cache'));
    }

    #[Test]
    public function neverMarksACallerBoundResponsePubliclyCacheable(): void
    {
        // A MISS used to answer `Cache-Control: public, max-age=60`, which tells
        // every intermediary proxy on the path that it too may keep and reshare
        // the response it just saw.
        $handler = $this->createHandler(new Response(statusCode: 200, body: 'Statement'));

        $request = $this->createGetRequest('/statement')->withHeader('Cookie', 'PULSARSESSID=alice');
        $response = $this->middleware->process($request, $handler);

        self::assertStringNotContainsString('public', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function doesNotStoreAResponseThatMintsACookie(): void
    {
        // Storing the response stores the Set-Cookie with it: the next requester
        // of that URL is handed the first caller's session or CSRF token.
        $handler = $this->createHandler(new Response(
            statusCode: 200,
            headers: ['Set-Cookie' => 'PULSARSESSID=alice; Path=/; HttpOnly'],
            body: 'Welcome',
        ));

        $request = $this->createGetRequest('/landing');
        $first = $this->middleware->process($request, $handler);
        self::assertSame('BYPASS', $first->getHeaderLine('X-Cache'));

        $second = $this->middleware->process($request, $handler);
        self::assertNotSame('HIT', $second->getHeaderLine('X-Cache'));
    }

    #[Test]
    public function doesNotStoreAResponseThatVariesOnSomethingTheKeyIgnores(): void
    {
        $handler = $this->createHandler(new Response(
            statusCode: 200,
            headers: ['Vary' => 'Cookie'],
            body: 'Varies',
        ));

        $request = $this->createGetRequest('/varies');
        $this->middleware->process($request, $handler);
        $second = $this->middleware->process($request, $handler);

        self::assertNotSame('HIT', $second->getHeaderLine('X-Cache'));
    }

    #[Test]
    public function doesNotStoreAStreamedResponse(): void
    {
        // A HIT is replayed as a plain buffered response. Storing the streamed
        // response's `Transfer-Encoding: chunked` with it would put a chunked
        // framing header in front of raw bytes on every hit.
        $handler = $this->createHandler(StreamedResponse::fromGenerator(
            static function (): Generator {
                yield 'row-1';
                yield 'row-2';
            },
        ));

        $request = $this->createGetRequest('/export');
        $first = $this->middleware->process($request, $handler);

        self::assertInstanceOf(StreamedResponse::class, $first);
        self::assertSame('BYPASS', $first->getHeaderLine('X-Cache'));
        self::assertSame('chunked', $first->getHeaderLine('Transfer-Encoding'));

        // And the refusal did not consume the stream on the way past.
        $streamed = '';

        foreach ($first->getSource() as $chunk) {
            self::assertIsString($chunk);
            $streamed .= $chunk;
        }

        self::assertSame('row-1row-2', $streamed);
    }

    #[Test]
    public function doesNotStoreAResponseTheOriginMarkedPrivate(): void
    {
        $handler = $this->createHandler(new Response(
            statusCode: 200,
            headers: ['Cache-Control' => 'private, max-age=300'],
            body: 'Yours only',
        ));

        $request = $this->createGetRequest('/mine');
        $this->middleware->process($request, $handler);
        $second = $this->middleware->process($request, $handler);

        self::assertNotSame('HIT', $second->getHeaderLine('X-Cache'));
    }

    #[Test]
    public function privateRoutesAreKeptOutOfTheSharedStoreEntirely(): void
    {
        // `cache.private` asked for a per-user answer. This middleware has one
        // shared store and no per-user one, so it declines rather than putting a
        // "private" response where every caller can be handed it.
        $handler = $this->createEchoingHandler();

        $request = $this->createGetRequest('/dashboard')->withAttribute('cache.private', true);
        $first = $this->middleware->process($request, $handler);

        self::assertSame('BYPASS', $first->getHeaderLine('X-Cache'));
        self::assertStringContainsString('private', $first->getHeaderLine('Cache-Control'));

        $second = $this->middleware->process($this->createGetRequest('/dashboard'), $handler);
        self::assertNotSame('HIT', $second->getHeaderLine('X-Cache'));
    }

    #[Test]
    public function aPrivateRouteDoesNotOverwriteTheHandlersOwnDirective(): void
    {
        // `private, max-age=60` in place of the handler's `no-store` would tell
        // the browser to keep what the origin just said must be kept nowhere.
        $handler = $this->createHandler(new Response(
            statusCode: 200,
            headers: ['Cache-Control' => 'no-store'],
            body: 'Secret',
        ));

        $request = $this->createGetRequest('/dashboard')->withAttribute('cache.private', true);
        $response = $this->middleware->process($request, $handler);

        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('BYPASS', $response->getHeaderLine('X-Cache'));
    }

    #[Test]
    public function shareAcrossClientsLetsAnIdentityBearingRequestUseTheSharedCache(): void
    {
        // The explicit, per-route opt-in: the application asserts this route's
        // answer does not depend on the caller.
        $handler = $this->createHandler(new Response(statusCode: 200, body: 'Public page'));

        $request = $this->createGetRequest('/pricing')
            ->withHeader('Cookie', 'analytics=1')
            ->withAttribute('cache.share_across_clients', true);

        self::assertSame('MISS', $this->middleware->process($request, $handler)->getHeaderLine('X-Cache'));
        self::assertSame('HIT', $this->middleware->process($request, $handler)->getHeaderLine('X-Cache'));
    }

    #[Test]
    public function shareAcrossClientsCannotOverruleTheResponseItself(): void
    {
        // The assertion is made before the answer exists. Evidence produced by
        // the handler still wins: a response minting a cookie is not shareable,
        // whatever the route declared.
        $handler = $this->createHandler(new Response(
            statusCode: 200,
            headers: ['Set-Cookie' => 'PULSARSESSID=alice; Path=/'],
            body: 'Welcome',
        ));

        $request = $this->createGetRequest('/pricing')
            ->withHeader('Cookie', 'analytics=1')
            ->withAttribute('cache.share_across_clients', true);

        self::assertSame('BYPASS', $this->middleware->process($request, $handler)->getHeaderLine('X-Cache'));
        self::assertNotSame('HIT', $this->middleware->process($request, $handler)->getHeaderLine('X-Cache'));
    }

    private function createGetRequest(string $path): ServerRequestInterface
    {
        return $this->createRequest('GET', $path);
    }

    /**
     * A handler whose body depends on the caller — the shape every personalised
     * page has, and the one a shared cache must never be allowed to store.
     */
    private function createEchoingHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(
                    statusCode: 200,
                    body: 'account of [' . $request->getHeaderLine('Cookie') . ']',
                );
            }
        };
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
