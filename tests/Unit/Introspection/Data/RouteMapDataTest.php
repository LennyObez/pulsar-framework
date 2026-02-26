<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Introspection\Data;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Introspection\Data\RouteEntry;
use Pulsar\Introspection\Data\RouteMapData;

#[CoversClass(RouteMapData::class)]
final class RouteMapDataTest extends TestCase
{
    #[Test]
    public function constructorDefaultsToEmptyRoutes(): void
    {
        $map = new RouteMapData();

        self::assertSame([], $map->routes);
    }

    #[Test]
    public function toArraySerializesRoutes(): void
    {
        $route = new RouteEntry(
            methods: ['GET'],
            path: '/api/users',
            handler: 'UserController::index',
            name: 'users.index',
        );

        $map = new RouteMapData(routes: [$route]);
        $array = $map->toArray();

        self::assertArrayHasKey('routes', $array);
        self::assertCount(1, $array['routes']);
        self::assertSame('/api/users', $array['routes'][0]['path']);
    }

    #[Test]
    public function toArrayWithEmptyRoutesReturnsEmptyArray(): void
    {
        $map = new RouteMapData();

        self::assertSame(['routes' => []], $map->toArray());
    }
}
