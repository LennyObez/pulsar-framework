<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Cache\Application\Lock\ArrayLock;
use Pulsar\Cache\Application\Lock\LockInterface;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Extension\Cms\Config\CmsCacheConfig;
use Pulsar\Extension\Cms\Http\Middleware\CmsPageCacheMiddleware;
use Pulsar\Extension\Cms\Internal\Cache\CmsCacheKeys;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Tenancy\Tenant;
use Random\Engine\Mt19937;
use Random\Randomizer;

use function array_keys;
use function in_array;
use function str_starts_with;
use function strlen;
use function substr;
use function time;

#[CoversClass(CmsPageCacheMiddleware::class)]
final class CmsPageCacheMiddlewareTest extends TestCase
{
    private ArrayTaggedCacheFake $cache;

    protected function setUp(): void
    {
        $this->cache = new ArrayTaggedCacheFake();
    }

    private function middleware(
        ?CmsCacheConfig $config = null,
        ?LockInterface $lock = null,
        ?string $sessionCookieName = null,
    ): CmsPageCacheMiddleware {
        return new CmsPageCacheMiddleware(
            cache: $this->cache,
            cacheConfig: $config ?? new CmsCacheConfig(pageCacheTtlSeconds: 3600),
            lock: $lock,
            sessionCookieName: $sessionCookieName,
            // Deterministic engine so XFetch draws are reproducible.
            randomizer: new Randomizer(new Mt19937(42)),
        );
    }

    private function htmlHandler(string $body = '<h1>Page</h1>', ?string $tagsHeader = null): RequestHandlerInterface
    {
        $response = Response::html($body);

        if ($tagsHeader !== null) {
            $response = $response->withHeader(CmsPageCacheMiddleware::TAGS_HEADER, $tagsHeader);
        }

        return new StaticHandler($response);
    }

    private function request(string $uri, string $method = 'GET'): ServerRequest
    {
        return new ServerRequest(method: $method, uri: $uri);
    }

    // ---------------------------------------------------------------- keys

    #[Test]
    public function the_key_varies_with_tenant_host_locale_and_query(): void
    {
        $middleware = $this->middleware();
        $handler = $this->htmlHandler();

        $base = $this->request('https://a.example/page');
        $middleware->process($base, $handler);
        $middleware->process($base->withAttribute('tenant_id', 'tenant-b'), $handler);
        $middleware->process($base->withAttribute('_tenant', new Tenant('tenant-c', 'C')), $handler);
        $middleware->process($this->request('https://other.example/page'), $handler);
        $middleware->process($base->withAttribute('cms_locale', 'fr'), $handler);
        $middleware->process($base->withQueryParams(['p' => '2']), $handler);

        self::assertCount(6, $this->cache->storedKeys(), 'Tenant, host, locale, and query must each mint a distinct entry');
    }

    #[Test]
    public function query_order_is_canonical_and_tracking_params_are_excluded(): void
    {
        $middleware = $this->middleware();
        $handler = $this->htmlHandler();

        $middleware->process(
            $this->request('/page')->withQueryParams(['b' => '2', 'a' => '1']),
            $handler,
        );
        $middleware->process(
            $this->request('/page')->withQueryParams(['a' => '1', 'b' => '2', 'utm_source' => 'x', 'fbclid' => 'y', 'gclid' => 'z', 'msclkid' => 'w', '_nocache' => '1']),
            $handler,
        );

        self::assertCount(1, $this->cache->storedKeys(), 'Reordered and tracking-suffixed queries must share one entry');
    }

    #[Test]
    public function head_shares_the_get_key_but_never_stores(): void
    {
        $middleware = $this->middleware();

        // A HEAD miss renders but must NOT store: its body may legitimately be
        // empty and would blank the page for every subsequent GET.
        $headResponse = $middleware->process($this->request('/page', 'HEAD'), $this->htmlHandler(''));
        self::assertSame('MISS', $headResponse->getHeaderLine('X-CMS-Cache'));
        self::assertCount(0, $this->cache->storedKeys());

        // A GET warms the entry; a subsequent HEAD is served from it.
        $middleware->process($this->request('/page'), $this->htmlHandler('<h1>Body</h1>'));
        $servedHead = $middleware->process($this->request('/page', 'HEAD'), new NeverCalledHandler());

        self::assertSame('HIT', $servedHead->getHeaderLine('X-CMS-Cache'));
    }

    // ------------------------------------------------------------- bypass

    #[Test]
    public function any_authenticated_identity_bypasses_the_shared_cache(): void
    {
        $middleware = $this->middleware();

        // No cms.* role — the old code let this user's page enter the SHARED
        // cache; any authenticated identity must bypass instead.
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('roles')->willReturn(['customer']);

        $response = $middleware->process(
            $this->request('/possibly-personalised')->withAttribute('identity', $identity),
            $this->htmlHandler('<p>personalised</p>'),
        );

        self::assertSame('', $response->getHeaderLine('X-CMS-Cache'));
        self::assertCount(0, $this->cache->storedKeys());
    }

    #[Test]
    public function a_session_cookie_bypasses_the_shared_cache(): void
    {
        $middleware = $this->middleware(sessionCookieName: 'pulsar_session');

        $request = $this->request('/page')->withCookieParams(['pulsar_session' => 'abc']);
        $response = $middleware->process($request, $this->htmlHandler());

        self::assertSame('', $response->getHeaderLine('X-CMS-Cache'));
        self::assertCount(0, $this->cache->storedKeys());
    }

    #[Test]
    public function anonymous_nocache_no_longer_busts_the_cache(): void
    {
        $middleware = $this->middleware();
        $handler = $this->htmlHandler();

        // _nocache used to be a free public cache-busting lever for anonymous
        // traffic; it now goes through the cache and stays key-neutral.
        $request = $this->request('/page')->withQueryParams(['_nocache' => '1']);

        self::assertSame('MISS', $middleware->process($request, $handler)->getHeaderLine('X-CMS-Cache'));
        self::assertSame('HIT', $middleware->process($request, $handler)->getHeaderLine('X-CMS-Cache'));
    }

    // ----------------------------------------------------------- storable

    /**
     * @return iterable<string, array{ResponseInterface}>
     */
    public static function unstorableResponses(): iterable
    {
        yield 'non-200' => [Response::html('<h1>gone</h1>')->withStatus(404)];
        yield 'non-html' => [Response::json(['a' => 1])];
        yield 'set-cookie' => [Response::html('<p>x</p>')->withHeader('Set-Cookie', 's=1')];
        yield 'no-store' => [Response::html('<p>x</p>')->withHeader('Cache-Control', 'no-store')];
        yield 'private' => [Response::html('<p>x</p>')->withHeader('Cache-Control', 'private, max-age=0')];
        yield 'csrf form token' => [Response::html('<form><input name="_csrf" value="t"></form>')];
        yield 'csrf meta token' => [Response::html('<meta name="csrf-token" content="t">')];
        yield 'csp nonce' => [Response::html('<script nonce="abc123">x()</script>')];
    }

    #[Test]
    #[DataProvider('unstorableResponses')]
    public function per_user_or_non_shareable_responses_are_never_stored(ResponseInterface $response): void
    {
        $middleware = $this->middleware();

        $middleware->process($this->request('/page'), new StaticHandler($response));

        self::assertCount(0, $this->cache->storedKeys());
    }

    // --------------------------------------------------------------- tags

    #[Test]
    public function declared_tags_are_consumed_merged_with_floor_tags_and_stripped(): void
    {
        $middleware = $this->middleware();

        $response = $middleware->process(
            $this->request('/article'),
            $this->htmlHandler('<h1>A</h1>', CmsCacheKeys::contentTag('42') . ',' . CmsCacheKeys::typeTag('article')),
        );

        self::assertSame('', $response->getHeaderLine(CmsPageCacheMiddleware::TAGS_HEADER), 'The internal header must never leave the server');

        $keys = $this->cache->storedKeys();
        self::assertCount(1, $keys);
        self::assertEqualsCanonicalizing(
            [CmsCacheKeys::TAG_ALL_PAGES, 'cms_content.42', 'cms_type.article', CmsCacheKeys::TAG_SETTINGS],
            $this->cache->tagsFor($keys[0]),
        );
    }

    #[Test]
    public function the_tags_header_is_stripped_even_on_bypass(): void
    {
        $middleware = $this->middleware();

        $response = $middleware->process(
            $this->request('/submit', 'POST'),
            $this->htmlHandler('<p>done</p>', 'cms_content.42'),
        );

        self::assertSame('', $response->getHeaderLine(CmsPageCacheMiddleware::TAGS_HEADER));
    }

    // ----------------------------------------------- stampede / staleness

    #[Test]
    public function within_grace_a_lock_loser_serves_stale_immediately_and_the_winner_regenerates(): void
    {
        $config = new CmsCacheConfig(pageCacheTtlSeconds: 60, staleGracePeriodSeconds: 300);
        $lock = new ArrayLock();
        $middleware = $this->middleware(config: $config, lock: $lock);

        // Warm, then age the entry past its logical expiry (still in grace).
        $middleware->process($this->request('/page'), $this->htmlHandler('<h1>old</h1>'));
        $this->cache->ageEnvelope(time() - 120);

        // A concurrent winner holds the regeneration lock: this request must
        // serve the stale copy immediately — never wait, never render.
        $key = $this->cache->storedKeys()[0];
        $heldResource = 'cms_page_lock.' . substr($key, strlen(CmsCacheKeys::PAGE_KEY_PREFIX));
        $held = $lock->acquire($heldResource, 30, 0);

        $stale = $middleware->process($this->request('/page'), new NeverCalledHandler());
        self::assertSame('STALE', $stale->getHeaderLine('X-CMS-Cache'));
        self::assertSame('<h1>old</h1>', (string) $stale->getBody());
        self::assertGreaterThanOrEqual(60, (int) $stale->getHeaderLine('Age'));

        $lock->release($held);

        // Lock free: the next request wins it and regenerates inline.
        $fresh = $middleware->process($this->request('/page'), $this->htmlHandler('<h1>new</h1>'));
        self::assertSame('MISS', $fresh->getHeaderLine('X-CMS-Cache'));
        self::assertSame('<h1>new</h1>', (string) $fresh->getBody());
    }

    #[Test]
    public function xfetch_elects_an_early_recompute_for_expensive_pages(): void
    {
        $config = new CmsCacheConfig(pageCacheTtlSeconds: 3600, earlyRecomputeBeta: 1.0);
        $middleware = $this->middleware(config: $config, lock: new ArrayLock());

        $middleware->process($this->request('/page'), $this->htmlHandler('<h1>v1</h1>'));

        // An astronomically expensive render (delta) makes the XFetch draw fire
        // for any u < 1 — deterministic with the seeded engine.
        $this->cache->setDelta(1.0e12);

        $response = $middleware->process($this->request('/page'), $this->htmlHandler('<h1>v2</h1>'));

        self::assertSame('MISS', $response->getHeaderLine('X-CMS-Cache'), 'The elected winner regenerates before expiry');
        self::assertSame('<h1>v2</h1>', (string) $response->getBody());
    }

    #[Test]
    public function cheap_fresh_pages_are_served_without_early_recompute(): void
    {
        $middleware = $this->middleware(config: new CmsCacheConfig(pageCacheTtlSeconds: 3600), lock: new ArrayLock());

        $middleware->process($this->request('/page'), $this->htmlHandler('<h1>v1</h1>'));
        $this->cache->setDelta(0.0);

        $response = $middleware->process($this->request('/page'), new NeverCalledHandler());

        self::assertSame('HIT', $response->getHeaderLine('X-CMS-Cache'));
        self::assertSame('0', $response->getHeaderLine('Age'));
    }

    #[Test]
    public function a_write_is_abandoned_when_an_invalidation_lands_mid_render(): void
    {
        $middleware = $this->middleware();

        // The handler simulates an editor publishing DURING the render by
        // bumping the invalidation epoch before returning its body.
        $cache = $this->cache;
        $handler = new class ($cache) implements RequestHandlerInterface {
            public function __construct(private readonly ArrayTaggedCacheFake $cache) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->cache->set(CmsCacheKeys::INVALIDATION_EPOCH_KEY, 'bumped-mid-render', [], null);

                return Response::html('<h1>may predate the publish</h1>');
            }
        };

        $response = $middleware->process($this->request('/page'), $handler);

        self::assertSame('MISS', $response->getHeaderLine('X-CMS-Cache'));
        self::assertCount(0, $this->cache->storedKeys(), 'A body rendered across an invalidation must not be pinned');
    }

    #[Test]
    public function cold_miss_double_checks_after_acquiring_the_lock(): void
    {
        $lock = new ArrayLock();
        $middleware = $this->middleware(config: new CmsCacheConfig(pageCacheTtlSeconds: 3600), lock: $lock);

        // The entry exists by the time this request gets the lock (a winner
        // finished while we queued): serve it, never re-render.
        $middleware->process($this->request('/page'), $this->htmlHandler('<h1>winner</h1>'));

        $served = $middleware->process($this->request('/page'), new NeverCalledHandler());
        self::assertSame('HIT', $served->getHeaderLine('X-CMS-Cache'));
    }

    #[Test]
    public function legacy_or_corrupt_entries_are_treated_as_misses(): void
    {
        $middleware = $this->middleware();

        // v1-era entries were JSON strings; anything but a v2 envelope re-renders.
        $request = $this->request('/page');
        $middleware->process($request, $this->htmlHandler('<h1>probe</h1>'));
        $key = $this->cache->storedKeys()[0];
        $this->cache->set($key, '{"body":"legacy","status":200,"headers":{}}', ['cms_pages'], 60);

        $response = $middleware->process($request, $this->htmlHandler('<h1>fresh</h1>'));

        self::assertSame('MISS', $response->getHeaderLine('X-CMS-Cache'));
        self::assertSame('<h1>fresh</h1>', (string) $response->getBody());
    }
}

/**
 * Handler double that fails the test if the middleware renders when it must not.
 */
final class NeverCalledHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        TestCase::fail('The downstream handler must not run on this path');
    }
}

final class StaticHandler implements RequestHandlerInterface
{
    public function __construct(private readonly ResponseInterface $response) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->response;
    }
}

/**
 * TaggedCacheInterface fake that stores values UNMODIFIED (arrays stay arrays,
 * matching the real TaggedCache round-trip) and exposes envelope surgery
 * helpers for staleness and XFetch tests.
 */
final class ArrayTaggedCacheFake implements TaggedCacheInterface
{
    /** @var array<string, mixed> */
    private array $data = [];

    /** @var array<string, list<string>> */
    private array $keyTags = [];

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $value, array $tags, ?int $ttlSeconds = null): bool
    {
        $this->data[$key] = $value;
        $this->keyTags[$key] = $tags;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->data[$key], $this->keyTags[$key]);

        return true;
    }

    public function invalidateTag(string $tag): void
    {
        foreach ($this->keyTags as $key => $tags) {
            if (in_array($tag, $tags, true)) {
                unset($this->data[$key], $this->keyTags[$key]);
            }
        }
    }

    public function invalidateTags(array $tags): void
    {
        foreach ($tags as $tag) {
            $this->invalidateTag($tag);
        }
    }

    /**
     * Page-entry keys only (excludes bookkeeping keys like the epoch).
     *
     * @return list<string>
     */
    public function storedKeys(): array
    {
        $keys = [];

        foreach (array_keys($this->data) as $key) {
            if (str_starts_with($key, CmsCacheKeys::PAGE_KEY_PREFIX)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    public function tagsFor(string $key): array
    {
        return $this->keyTags[$key] ?? [];
    }

    /**
     * Rewrite every stored envelope's creation time (and derived expiry) as if
     * it had been written at $createdAt.
     */
    public function ageEnvelope(int $createdAt): void
    {
        foreach ($this->storedKeys() as $key) {
            /** @var array{createdAt: int, expiresAt: int}&array<string, mixed> $envelope */
            $envelope = $this->data[$key];
            $ttl = $envelope['expiresAt'] - $envelope['createdAt'];
            $envelope['createdAt'] = $createdAt;
            $envelope['expiresAt'] = $createdAt + $ttl;
            $this->data[$key] = $envelope;
        }
    }

    public function setDelta(float $deltaMs): void
    {
        foreach ($this->storedKeys() as $key) {
            /** @var array<string, mixed> $envelope */
            $envelope = $this->data[$key];
            $envelope['delta'] = $deltaMs;
            $this->data[$key] = $envelope;
        }
    }
}
