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
use Pulsar\Extension\Cms\Internal\Cache\CmsCacheInvalidator;
use Pulsar\Extension\Cms\Internal\Cache\CmsCacheKeys;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;

use function in_array;

/**
 * End-to-end flows of the CMS page cache against an in-memory tagged cache:
 * warm/hit, tag-driven invalidation through CmsCacheInvalidator (the real
 * invalidation entry point), and the bypass rules.
 */
#[CoversClass(CmsPageCacheMiddleware::class)]
#[CoversClass(CmsCacheInvalidator::class)]
final class PageCacheTest extends TestCase
{
    private InMemoryTaggedCache $cache;
    private CmsPageCacheMiddleware $middleware;
    private CmsCacheInvalidator $invalidator;

    protected function setUp(): void
    {
        $this->cache = new InMemoryTaggedCache();
        $config = new CmsCacheConfig(pageCacheTtlSeconds: 3600);
        $this->middleware = new CmsPageCacheMiddleware($this->cache, $config);
        $this->invalidator = new CmsCacheInvalidator($this->cache);
    }

    #[Test]
    public function requestPublishedPageIsCached(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/blog/hello-world');

        $handler = $this->createHandlerReturning(Response::html('<h1>Hello World</h1>'));

        // First request: MISS
        $response1 = $this->middleware->process($request, $handler);
        self::assertSame(200, $response1->getStatusCode());
        self::assertSame('MISS', $response1->getHeaderLine('X-CMS-Cache'));

        // Second request: HIT (served from cache), with an RFC 9111 Age header
        $response2 = $this->middleware->process($request, $handler);
        self::assertSame(200, $response2->getStatusCode());
        self::assertSame('HIT', $response2->getHeaderLine('X-CMS-Cache'));
        self::assertSame('0', $response2->getHeaderLine('Age'));
        self::assertStringContainsString('Hello World', (string) $response2->getBody());
    }

    #[Test]
    public function publishingContentInvalidatesThePagesThatRenderedIt(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/blog/hello-world');

        // The content pipeline declares the page's tags via the internal
        // response header, exactly as ContentController does.
        $this->middleware->process($request, $this->createHandlerReturning(
            Response::html('<h1>Original</h1>')
                ->withHeader(CmsPageCacheMiddleware::TAGS_HEADER, CmsCacheKeys::contentTag('content-001')),
        ));

        // An editor publishes: the REAL invalidation path.
        $this->invalidator->invalidateContent('content-001');

        $response = $this->middleware->process($request, $this->createHandlerReturning(
            Response::html('<h1>Updated</h1>'),
        ));

        self::assertSame('MISS', $response->getHeaderLine('X-CMS-Cache'));
        self::assertStringContainsString('Updated', (string) $response->getBody());
    }

    #[Test]
    public function menuChangesInvalidateEveryCachedPage(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/about');

        $this->middleware->process($request, $this->createHandlerReturning(
            Response::html('<nav>Old Menu</nav><main>Content</main>'),
        ));

        self::assertSame(
            'HIT',
            $this->middleware->process($request, $this->createHandlerReturning(Response::html('x')))
                ->getHeaderLine('X-CMS-Cache'),
        );

        // Pages cannot know which menus they rendered, so a menu change
        // invalidates the coarse page tag through the invalidator.
        $this->invalidator->invalidateMenu('menu-primary');

        $fresh = $this->middleware->process($request, $this->createHandlerReturning(
            Response::html('<nav>New Menu</nav><main>Content</main>'),
        ));

        self::assertSame('MISS', $fresh->getHeaderLine('X-CMS-Cache'));
        self::assertStringContainsString('New Menu', (string) $fresh->getBody());
    }

    #[Test]
    public function settingsChangesInvalidateEveryCachedPage(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/');

        $this->middleware->process($request, $this->createHandlerReturning(Response::html('<h1>v1</h1>')));
        $this->invalidator->invalidateSettings();

        $fresh = $this->middleware->process($request, $this->createHandlerReturning(Response::html('<h1>v2</h1>')));

        self::assertSame('MISS', $fresh->getHeaderLine('X-CMS-Cache'));
    }

    #[Test]
    public function authenticatedUsersBypassCacheEntirely(): void
    {
        $identity = new TestIdentity(
            id: 'user-001',
            displayName: 'Any User',
            roles: ['customer'],
            authenticated: true,
        );

        $request = new ServerRequest(method: 'GET', uri: '/blog/hello-world')
            ->withAttribute('identity', $identity);

        $response = $this->middleware->process(
            $request,
            $this->createHandlerReturning(Response::html('Fresh personalised content')),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('X-CMS-Cache'));
        self::assertStringContainsString('Fresh', (string) $response->getBody());
    }

    #[Test]
    public function nonGetRequestsBypassCache(): void
    {
        $request = new ServerRequest(method: 'POST', uri: '/blog/hello-world');

        $handler = $this->createHandlerReturning(new Response(statusCode: 302, body: ''));

        $response = $this->middleware->process($request, $handler);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('X-CMS-Cache'));
    }

    #[Test]
    public function anonymousNocacheGoesThroughTheCache(): void
    {
        // _nocache was a free public cache-busting lever; anonymous requests
        // now hit the cache and the parameter does not mint a variant either.
        $plain = new ServerRequest(method: 'GET', uri: '/blog/hello-world');
        $busted = new ServerRequest(method: 'GET', uri: '/blog/hello-world?_nocache=1')
            ->withQueryParams(['_nocache' => '1']);

        $handler = $this->createHandlerReturning(Response::html('Cached once'));

        self::assertSame('MISS', $this->middleware->process($plain, $handler)->getHeaderLine('X-CMS-Cache'));
        self::assertSame('HIT', $this->middleware->process($busted, $handler)->getHeaderLine('X-CMS-Cache'));
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

/**
 * Values are stored UNMODIFIED (the real TaggedCache round-trips arrays), and
 * tag invalidation drops every key carrying the tag.
 */
final class InMemoryTaggedCache implements TaggedCacheInterface
{
    /** @var array<string, mixed> */
    private array $data = [];

    /** @var array<string, list<string>> key => tags */
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
