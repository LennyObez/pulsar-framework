<?php

declare(strict_types=1);

namespace Pulsar\Extension\Devices\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extension\Devices\DevicesExtension;
use Pulsar\Extension\Devices\DevicesServiceProvider;
use Pulsar\Routing\RouterInterface;

final class DevicesExtensionTest extends TestCase
{
    #[Test]
    public function implementsExtensionInterface(): void
    {
        $ext = new DevicesExtension();

        self::assertInstanceOf(ExtensionInterface::class, $ext);
    }

    #[Test]
    public function nameReturnsPulsarDevices(): void
    {
        $ext = new DevicesExtension();

        self::assertSame('pulsar/devices', $ext->name());
    }

    #[Test]
    public function providersReturnsDevicesServiceProvider(): void
    {
        $ext = new DevicesExtension();
        $providers = $ext->providers();

        self::assertCount(1, $providers);
        self::assertSame(DevicesServiceProvider::class, $providers[0]);
    }

    #[Test]
    public function bootRegistersApiRoutes(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $router = $this->createMock(RouterInterface::class);

        $router->expects(self::once())->method('get');
        $router->expects(self::exactly(2))->method('post');
        $router->expects(self::once())->method('delete');

        $ext = new DevicesExtension();
        $ext->boot($container, $router);
    }
}
