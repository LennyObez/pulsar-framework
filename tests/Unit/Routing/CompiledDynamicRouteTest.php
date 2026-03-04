<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Routing\CompiledDynamicRoute;
use Pulsar\Routing\CompiledRouteEntry;

#[CoversClass(CompiledDynamicRoute::class)]
final class CompiledDynamicRouteTest extends TestCase
{
    #[Test]
    public function constructionStoresAllProperties(): void
    {
        $entry = new CompiledRouteEntry(
            methods: ['GET'],
            path: '/users/{id}',
            handler: 'App\\Controller\\UserController',
        );

        $dynamic = new CompiledDynamicRoute(
            pattern: '#^/users/(?P<id>[^/]+)$#',
            entry: $entry,
            host: '{tenant}.example.com',
            hostPattern: '#^(?P<tenant>[^.]+)\\.example\\.com$#',
        );

        self::assertSame('#^/users/(?P<id>[^/]+)$#', $dynamic->pattern);
        self::assertSame($entry, $dynamic->entry);
        self::assertSame('{tenant}.example.com', $dynamic->host);
        self::assertSame('#^(?P<tenant>[^.]+)\\.example\\.com$#', $dynamic->hostPattern);
    }

    #[Test]
    public function hostDefaultsToNull(): void
    {
        $entry = new CompiledRouteEntry(
            methods: ['GET'],
            path: '/posts/{slug}',
            handler: 'App\\Controller\\PostController',
        );

        $dynamic = new CompiledDynamicRoute(
            pattern: '#^/posts/(?P<slug>[^/]+)$#',
            entry: $entry,
        );

        self::assertNull($dynamic->host);
        self::assertNull($dynamic->hostPattern);
    }

    #[Test]
    public function patternCanMatchPaths(): void
    {
        $entry = new CompiledRouteEntry(
            methods: ['GET'],
            path: '/articles/{year}/{slug}',
            handler: 'App\\Controller\\ArticleController',
        );

        $dynamic = new CompiledDynamicRoute(
            pattern: '#^/articles/(?P<year>\d{4})/(?P<slug>[^/]+)$#',
            entry: $entry,
        );

        $result = preg_match($dynamic->pattern, '/articles/2026/hello-world', $matches);

        self::assertSame(1, $result);
        self::assertSame('2026', $matches['year']);
        self::assertSame('hello-world', $matches['slug']);
    }

    #[Test]
    public function patternRejectsNonMatchingPaths(): void
    {
        $entry = new CompiledRouteEntry(
            methods: ['GET'],
            path: '/articles/{year}/{slug}',
            handler: 'App\\Controller\\ArticleController',
        );

        $dynamic = new CompiledDynamicRoute(
            pattern: '#^/articles/(?P<year>\d{4})/(?P<slug>[^/]+)$#',
            entry: $entry,
        );

        $result = preg_match($dynamic->pattern, '/blog/2026/hello', $matches);

        self::assertSame(0, $result);
    }
}
