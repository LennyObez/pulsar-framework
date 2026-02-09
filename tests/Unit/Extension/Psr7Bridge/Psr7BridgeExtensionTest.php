<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Psr7Bridge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Container;
use Pulsar\Extension\Psr7Bridge\Adapter\Psr7ToPulsarRequest;
use Pulsar\Extension\Psr7Bridge\Adapter\Psr7ToPulsarResponse;
use Pulsar\Extension\Psr7Bridge\Adapter\PulsarToPsr7Request;
use Pulsar\Extension\Psr7Bridge\Adapter\PulsarToPsr7Response;
use Pulsar\Extension\Psr7Bridge\Psr7BridgeExtension;
use Pulsar\Routing\Router;

#[CoversClass(Psr7BridgeExtension::class)]
final class Psr7BridgeExtensionTest extends TestCase
{
    #[Test]
    public function nameReturnsPulsarPsr7Bridge(): void
    {
        $extension = new Psr7BridgeExtension();

        self::assertSame('pulsar/psr7-bridge', $extension->name());
    }

    #[Test]
    public function registerBindsAdapterServices(): void
    {
        $extension = new Psr7BridgeExtension();
        $container = new Container();

        $extension->register($container);

        self::assertTrue($container->has(PulsarToPsr7Request::class));
        self::assertTrue($container->has(Psr7ToPulsarRequest::class));
        self::assertTrue($container->has(PulsarToPsr7Response::class));
        self::assertTrue($container->has(Psr7ToPulsarResponse::class));
    }

    #[Test]
    public function bootDoesNotRegisterRoutes(): void
    {
        $extension = new Psr7BridgeExtension();
        $container = new Container();
        $router = new Router();

        $extension->boot($container, $router);

        self::assertSame(0, $router->count());
    }

    #[Test]
    public function providersReturnsEmptyArray(): void
    {
        $extension = new Psr7BridgeExtension();

        self::assertSame([], $extension->providers());
    }
}
