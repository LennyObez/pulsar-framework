<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Container\Compiled;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Compiled\RouteServiceManifest;

#[CoversClass(RouteServiceManifest::class)]
final class RouteServiceManifestTest extends TestCase
{
    #[Test]
    public function emptyManifestReturnsNoServices(): void
    {
        $manifest = new RouteServiceManifest();

        self::assertSame([], $manifest->servicesForRoute('GET:/users'));
        self::assertFalse($manifest->hasRoute('GET:/users'));
        self::assertSame(0, $manifest->count());
        self::assertSame([], $manifest->routeKeys());
    }

    #[Test]
    public function registerAndRetrieveRouteServices(): void
    {
        $manifest = new RouteServiceManifest();
        $manifest->registerRoute('GET:/users', ['UserRepository', 'Logger']);

        self::assertTrue($manifest->hasRoute('GET:/users'));
        self::assertSame(['UserRepository', 'Logger'], $manifest->servicesForRoute('GET:/users'));
        self::assertSame(1, $manifest->count());
    }

    #[Test]
    public function deduplicatesServiceIds(): void
    {
        $manifest = new RouteServiceManifest();
        $manifest->registerRoute('POST:/orders', ['DbConnection', 'Logger', 'DbConnection']);

        self::assertSame(['DbConnection', 'Logger'], $manifest->servicesForRoute('POST:/orders'));
    }

    #[Test]
    public function routeKeysReturnsAllRegisteredKeys(): void
    {
        $manifest = new RouteServiceManifest();
        $manifest->registerRoute('GET:/users', ['UserRepo']);
        $manifest->registerRoute('POST:/orders', ['OrderRepo']);
        $manifest->registerRoute('DELETE:/sessions', ['SessionManager']);

        $keys = $manifest->routeKeys();
        self::assertCount(3, $keys);
        self::assertContains('GET:/users', $keys);
        self::assertContains('POST:/orders', $keys);
        self::assertContains('DELETE:/sessions', $keys);
    }

    #[Test]
    public function toArrayExportsAllData(): void
    {
        $manifest = new RouteServiceManifest([
            'GET:/a' => ['ServiceA'],
            'POST:/b' => ['ServiceB', 'ServiceC'],
        ]);

        $exported = $manifest->toArray();

        self::assertSame([
            'GET:/a' => ['ServiceA'],
            'POST:/b' => ['ServiceB', 'ServiceC'],
        ], $exported);
    }

    #[Test]
    public function fromArrayCreatesEquivalentManifest(): void
    {
        $data = [
            'GET:/health' => ['HealthCheck'],
            'POST:/api/data' => ['DataService', 'Validator'],
        ];

        $manifest = RouteServiceManifest::fromArray($data);

        self::assertTrue($manifest->hasRoute('GET:/health'));
        self::assertSame(['HealthCheck'], $manifest->servicesForRoute('GET:/health'));
        self::assertSame(['DataService', 'Validator'], $manifest->servicesForRoute('POST:/api/data'));
        self::assertSame(2, $manifest->count());
    }

    #[Test]
    public function roundTripPreservesData(): void
    {
        $original = [
            'GET:/users' => ['UserRepository', 'Logger'],
            'POST:/users' => ['UserRepository', 'Validator', 'EventDispatcher'],
        ];

        $manifest = RouteServiceManifest::fromArray($original);
        $exported = $manifest->toArray();

        self::assertSame($original, $exported);
    }

    #[Test]
    public function missingRouteReturnsEmptyArray(): void
    {
        $manifest = new RouteServiceManifest(['GET:/exists' => ['Svc']]);

        self::assertSame([], $manifest->servicesForRoute('GET:/nonexistent'));
    }

    #[Test]
    public function registerOverwritesPreviousEntry(): void
    {
        $manifest = new RouteServiceManifest();
        $manifest->registerRoute('GET:/users', ['OldService']);
        $manifest->registerRoute('GET:/users', ['NewService']);

        self::assertSame(['NewService'], $manifest->servicesForRoute('GET:/users'));
    }
}
