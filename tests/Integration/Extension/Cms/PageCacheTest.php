<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Cms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Extension\Cms\Config\CmsCacheConfig;
use Pulsar\Extension\Cms\Http\Middleware\CmsPageCacheMiddleware;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;

use function in_array;
use function is_string;
use function json_encode;

#[CoversClass(CmsPageCacheMiddleware::class)]
final class PageCacheTest extends TestCase
{
    private InMemoryTaggedCache $cache;
    private CmsPageCacheMiddleware $middleware;

    protected function setUp(): void
    {
        $this->cache = new InMemoryTaggedCache();
        $config = new CmsCacheConfig(pageCacheTtlSeconds: 3600);
        $this->middleware = new CmsPageCacheMiddleware($this->cache, $config);
    }

    #[Test]
    public function requestPublishedPageIsCached(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/blog/hello-world', headers: []);

        $handler = $this->createHandlerReturning(new Response(
            statusCode: 200,
            headers: ['Content-Type' => 'text/html'],
            body: '<h1>Hello World</h1>',
        ));

        // First request: MISS
        $response1 = $this->middleware->process($request, $handler);
        self::assertSame(200, $response1->getStatusCode());
        self::assertSame('MISS', $response1->getHeaderLine('X-CMS-Cache'));

        // Second request: HIT (served from cache)
        $response2 = $this->middleware->process($request, $handler);
        self::assertSame(200, $response2->getStatusCode());
        self::assertSame('HIT', $response2->getHeaderLine('X-CMS-Cache'));
        self::assertStringContainsString('Hello World', (string) $response2->getBody());
    }

    #[Test]
    public function cacheInvalidatedOnContentUpdate(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/blog/hello-world', headers: []);
        $request = $request->withAttribute('cms_content_id', 'content-001');

        $handler = $this->createHandlerReturning(new Response(
            statusCode: 200,
            headers: ['Content-Type' => 'text/html'],
            body: '<h1>Original</h1>',
        ));

        // Warm the cache
        $this->middleware->process($request, $handler);

        // Simulate content update by invalidating the tag
        $this->cache->invalidateTag('cms_content.content-001');

        // Next request should be a MISS (cache invalidated)
        $updatedHandler = $this->createHandlerReturning(new Response(
            statusCode: 200,
            headers: ['Content-Type' => 'text/html'],
            body: '<h1>Updated</h1>',
        ));

        $response = $this->middleware->process($request, $updatedHandler);
        self::assertSame('MISS', $response->getHeaderLine('X-CMS-Cache'));
        self::assertStringContainsString('Updated', (string) $response->getBody());
    }

    #[Test]
    public function freshContentServedAfterInvalidation(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/blog/article', headers: []);
        $request = $request->withAttribute('cms_content_id', 'content-002');

        // Warm cache with "Version 1"
        $this->middleware->process($request, $this->createHandlerReturning(new Response(
            statusCode: 200,
            body: 'Version 1',
        )));

        // Verify cached
        $cachedResponse = $this->middleware->process($request, $this->createHandlerReturning(new Response(
            statusCode: 200,
            body: 'Should not see this',
        )));
        self::assertSame('HIT', $cachedResponse->getHeaderLine('X-CMS-Cache'));

        // Invalidate
        $this->cache->invalidateTag('cms_content.content-002');

        // Fresh response
        $freshResponse = $this->middleware->process($request, $this->createHandlerReturning(new Response(
            statusCode: 200,
            body: 'Version 2',
        )));
        self::assertSame('MISS', $freshResponse->getHeaderLine('X-CMS-Cache'));
        self::assertSame('Version 2', (string) $freshResponse->getBody());
    }

    #[Test]
    public function adminUsersBypassCache(): void
    {
        $identity = new TestIdentity(
            id: 'admin-001',
            displayName: 'Admin',
            roles: ['cms.admin'],
            authenticated: true,
        );

        $request = new ServerRequest(method: 'GET', uri: '/blog/hello-world', headers: []);
        $request = $request->withAttribute('identity', $identity);

        $handler = $this->createHandlerReturning(new Response(
            statusCode: 200,
            body: 'Admin sees fresh content',
        ));

        // Admin request bypasses cache entirely (no X-CMS-Cache header)
        $response = $this->middleware->process($request, $handler);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('X-CMS-Cache'));
        self::assertSame('Admin sees fresh content', (string) $response->getBody());
    }

    #[Test]
    public function tagBasedInvalidationOnMenuChange(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/about', headers: []);
        $request = $request->withAttribute('cms_menu_ids', ['menu-primary']);

        // Warm the cache
        $this->middleware->process($request, $this->createHandlerReturning(new Response(
            statusCode: 200,
            body: '<nav>Old Menu</nav><main>Content</main>',
        )));

        // Verify cached
        $cached = $this->middleware->process($request, $this->createHandlerReturning(new Response(
            statusCode: 200,
            body: 'Should not see this',
        )));
        self::assertSame('HIT', $cached->getHeaderLine('X-CMS-Cache'));

        // Invalidate the menu tag
        $this->cache->invalidateTag('cms_menu.menu-primary');

        // Page should be re-fetched
        $fresh = $this->middleware->process($request, $this->createHandlerReturning(new Response(
            statusCode: 200,
            body: '<nav>New Menu</nav><main>Content</main>',
        )));
        self::assertSame('MISS', $fresh->getHeaderLine('X-CMS-Cache'));
        self::assertStringContainsString('New Menu', (string) $fresh->getBody());
    }

    #[Test]
    public function nonGetRequestsBypassCache(): void
    {
        $request = new ServerRequest(method: 'POST', uri: '/blog/hello-world', headers: []);

        $handler = $this->createHandlerReturning(new Response(statusCode: 302, body: ''));

        $response = $this->middleware->process($request, $handler);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('X-CMS-Cache'));
    }

    #[Test]
    public function nocacheQueryParamBypassesCache(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/blog/hello-world?_nocache=1', headers: [], queryParams: ['_nocache' => '1']);

        $handler = $this->createHandlerReturning(new Response(statusCode: 200, body: 'Fresh'));

        $response = $this->middleware->process($request, $handler);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('X-CMS-Cache'));
    }

    private function createHandlerReturning(ResponseInterface $response): RequestHandlerInterface
    {
        return new class ($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }
}

final class InMemoryTaggedCache implements TaggedCacheInterface
{
    /** @var array<string, string> */
    private array $data = [];

    /** @var array<string, list<string>> key => tags */
    private array $keyTags = [];

    /** @var array<string, true> invalidated tags */
    private array $invalidatedTags = [];

    public function get(string $key): mixed
    {
        if (!isset($this->data[$key])) {
            return null;
        }

        // Check if any tag for this key has been invalidated
        foreach ($this->keyTags[$key] ?? [] as $tag) {
            if (isset($this->invalidatedTags[$tag])) {
                unset($this->data[$key], $this->keyTags[$key]);

                return null;
            }
        }

        return $this->data[$key];
    }

    public function set(string $key, mixed $value, array $tags, ?int $ttlSeconds = null): bool
    {
        // Clear invalidated tags for freshly stored entries
        foreach ($tags as $tag) {
            unset($this->invalidatedTags[$tag]);
        }

        $this->data[$key] = is_string($value) ? $value : (string) json_encode($value);
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
        $this->invalidatedTags[$tag] = true;
    }

    public function invalidateTags(array $tags): void
    {
        foreach ($tags as $tag) {
            $this->invalidateTag($tag);
        }
    }
}

final class TestIdentity implements IdentityInterface
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        private readonly string $id,
        private readonly string $displayName,
        private readonly array $roles,
        private readonly bool $authenticated,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function roles(): array
    {
        return $this->roles;
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function twoFactorStatus(): TwoFactorStatus
    {
        return TwoFactorStatus::Disabled;
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    public function attributes(): array
    {
        return [];
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $default;
    }
}
