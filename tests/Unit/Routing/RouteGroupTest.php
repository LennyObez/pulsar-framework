<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Method;
use Pulsar\Routing\Route;
use Pulsar\Routing\RouteGroup;

#[CoversClass(RouteGroup::class)]
final class RouteGroupTest extends TestCase
{
    #[Test]
    public function prefixPropertyReturnsPrefix(): void
    {
        $group = new RouteGroup(prefix: '/api');

        self::assertSame('/api', $group->prefix);
    }

    #[Test]
    public function middlewarePropertyReturnsMiddleware(): void
    {
        $group = new RouteGroup(middleware: ['auth', 'throttle']);

        self::assertSame(['auth', 'throttle'], $group->middleware);
    }

    #[Test]
    public function attributesPropertyReturnsAttributes(): void
    {
        $group = new RouteGroup(attributes: ['version' => 'v1']);

        self::assertSame(['version' => 'v1'], $group->attributes);
    }

    #[Test]
    public function flattenSingleRoute(): void
    {
        $group = new RouteGroup(prefix: '/api');
        $group->add(new Route(
            methods: [Method::GET],
            path: '/users',
            handler: static fn(): string => 'ok',
            name: 'api.users',
        ));

        $routes = $group->flatten();

        self::assertCount(1, $routes);
        self::assertSame('/api/users', $routes[0]->path);
        self::assertSame('api.users', $routes[0]->name);
    }

    #[Test]
    public function flattenCombinesMiddleware(): void
    {
        $group = new RouteGroup(
            prefix: '/admin',
            middleware: ['auth'],
        );
        $group->add(new Route(
            methods: [Method::GET],
            path: '/dashboard',
            handler: static fn(): string => 'ok',
            middleware: ['throttle'],
        ));

        $routes = $group->flatten();

        self::assertSame(['auth', 'throttle'], $routes[0]->middleware);
    }

    #[Test]
    public function flattenCombinesAttributes(): void
    {
        $group = new RouteGroup(
            prefix: '/admin',
            attributes: ['section' => 'admin'],
        );
        $group->add(new Route(
            methods: [Method::GET],
            path: '/users',
            handler: static fn(): string => 'ok',
            attributes: ['permissions' => ['users.list']],
        ));

        $routes = $group->flatten();

        self::assertSame('admin', $routes[0]->attributes['section']);
        self::assertSame(['users.list'], $routes[0]->attributes['permissions']);
    }

    #[Test]
    public function flattenNestedGroups(): void
    {
        $inner = new RouteGroup(prefix: '/v1');
        $inner->add(new Route(
            methods: [Method::GET],
            path: '/items',
            handler: static fn(): string => 'ok',
        ));

        $outer = new RouteGroup(prefix: '/api');
        $outer->group($inner);

        $routes = $outer->flatten();

        self::assertCount(1, $routes);
        self::assertSame('/api/v1/items', $routes[0]->path);
    }

    #[Test]
    public function flattenUsesGroupHostAsDefault(): void
    {
        $group = new RouteGroup(prefix: '/api', host: 'api.example.com');
        $group->add(new Route(
            methods: [Method::GET],
            path: '/users',
            handler: static fn(): string => 'ok',
        ));

        $routes = $group->flatten();

        self::assertSame('api.example.com', $routes[0]->host);
    }

    #[Test]
    public function flattenRouteHostOverridesGroupHost(): void
    {
        $group = new RouteGroup(prefix: '/api', host: 'api.example.com');
        $group->add(new Route(
            methods: [Method::GET],
            path: '/special',
            handler: static fn(): string => 'ok',
            host: 'special.example.com',
        ));

        $routes = $group->flatten();

        self::assertSame('special.example.com', $routes[0]->host);
    }

    #[Test]
    public function addReturnsSelf(): void
    {
        $group = new RouteGroup();
        $result = $group->add(new Route(
            methods: [Method::GET],
            path: '/',
            handler: static fn(): string => 'ok',
        ));

        self::assertSame($group, $result);
    }

    #[Test]
    public function groupReturnsSelf(): void
    {
        $group = new RouteGroup();
        $result = $group->group(new RouteGroup());

        self::assertSame($group, $result);
    }
}
