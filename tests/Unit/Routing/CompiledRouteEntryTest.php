<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Method;
use Pulsar\Routing\CompiledRouteEntry;

#[CoversClass(CompiledRouteEntry::class)]
final class CompiledRouteEntryTest extends TestCase
{
    #[Test]
    public function constructionStoresAllProperties(): void
    {
        $entry = new CompiledRouteEntry(
            methods: ['GET', 'POST'],
            path: '/api/users/{id}',
            handler: 'App\\Controller\\UserController',
            name: 'user.show',
            attributes: ['section' => 'api'],
            middleware: ['auth', 'throttle'],
            constraints: ['id' => '\\d+'],
            host: 'api.example.com',
        );

        self::assertSame(['GET', 'POST'], $entry->methods);
        self::assertSame('/api/users/{id}', $entry->path);
        self::assertSame('App\\Controller\\UserController', $entry->handler);
        self::assertSame('user.show', $entry->name);
        self::assertSame(['section' => 'api'], $entry->attributes);
        self::assertSame(['auth', 'throttle'], $entry->middleware);
        self::assertSame(['id' => '\\d+'], $entry->constraints);
        self::assertSame('api.example.com', $entry->host);
    }

    #[Test]
    public function defaultsAreApplied(): void
    {
        $entry = new CompiledRouteEntry(
            methods: ['GET'],
            path: '/home',
            handler: 'App\\Controller\\HomeController',
        );

        self::assertNull($entry->name);
        self::assertSame([], $entry->attributes);
        self::assertSame([], $entry->middleware);
        self::assertSame([], $entry->constraints);
        self::assertNull($entry->host);
    }

    #[Test]
    public function toRouteReconstructsRouteObject(): void
    {
        $entry = new CompiledRouteEntry(
            methods: ['GET'],
            path: '/dashboard',
            handler: 'App\\Controller\\DashboardController',
            name: 'dashboard',
            middleware: ['auth'],
        );

        $route = $entry->toRoute();

        self::assertSame('/dashboard', $route->path);
        self::assertSame('App\\Controller\\DashboardController', $route->handler);
        self::assertSame('dashboard', $route->name);
        self::assertSame(['auth'], $route->middleware);
        self::assertContains(Method::GET, $route->methods);
    }

    #[Test]
    public function toRouteConvertsMethodStringsToEnums(): void
    {
        $entry = new CompiledRouteEntry(
            methods: ['POST', 'PUT'],
            path: '/api/resource',
            handler: 'App\\Controller\\ResourceController',
        );

        $route = $entry->toRoute();
        $methods = $route->methods;

        self::assertCount(2, $methods);
        self::assertSame(Method::POST, $methods[0]);
        self::assertSame(Method::PUT, $methods[1]);
    }

    /**
     * @return class-string
     */
    private static function classString(string $value): string
    {
        /** @var class-string */
        return $value;
    }

    #[Test]
    public function arrayHandlerIsPreserved(): void
    {
        $entry = new CompiledRouteEntry(
            methods: ['GET'],
            path: '/api/v2/items',
            handler: [self::classString('App\\Controller\\ItemController'), 'list'],
        );

        self::assertSame(['App\\Controller\\ItemController', 'list'], $entry->handler);

        $route = $entry->toRoute();
        self::assertSame(['App\\Controller\\ItemController', 'list'], $route->handler);
    }
}
