<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Introspection\Data;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Introspection\Data\RouteEntry;

#[CoversClass(RouteEntry::class)]
final class RouteEntryTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $entry = new RouteEntry(
            methods: ['GET', 'HEAD'],
            path: '/users/{id}',
            handler: 'App\\Controller\\UserController::show',
            name: 'users.show',
            middleware: ['auth', 'throttle'],
        );

        self::assertSame(['GET', 'HEAD'], $entry->methods);
        self::assertSame('/users/{id}', $entry->path);
        self::assertSame('App\\Controller\\UserController::show', $entry->handler);
        self::assertSame('users.show', $entry->name);
        self::assertSame(['auth', 'throttle'], $entry->middleware);
    }

    #[Test]
    public function constructorDefaultsNameToNullAndMiddlewareToEmpty(): void
    {
        $entry = new RouteEntry(
            methods: ['POST'],
            path: '/api/webhook',
            handler: 'WebhookController::handle',
        );

        self::assertNull($entry->name);
        self::assertSame([], $entry->middleware);
    }

    #[Test]
    public function toArrayReturnsExpectedStructure(): void
    {
        $entry = new RouteEntry(
            methods: ['GET'],
            path: '/health',
            handler: 'HealthController::check',
            name: 'health.check',
            middleware: ['cors'],
        );

        $array = $entry->toArray();

        self::assertSame(['GET'], $array['methods']);
        self::assertSame('/health', $array['path']);
        self::assertSame('HealthController::check', $array['handler']);
        self::assertSame('health.check', $array['name']);
        self::assertSame(['cors'], $array['middleware']);
    }

    #[Test]
    public function toArrayIncludesNullName(): void
    {
        $entry = new RouteEntry(
            methods: ['DELETE'],
            path: '/api/resource',
            handler: 'ResourceController::delete',
        );

        $array = $entry->toArray();

        self::assertNull($array['name']);
    }
}
