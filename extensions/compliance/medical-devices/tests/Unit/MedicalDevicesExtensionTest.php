<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\MedicalDevices\MedicalDevicesExtension;
use Pulsar\Extension\MedicalDevices\MedicalDevicesServiceProvider;
use Pulsar\Routing\RouterInterface;

#[CoversClass(MedicalDevicesExtension::class)]
final class MedicalDevicesExtensionTest extends TestCase
{
    private MedicalDevicesExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new MedicalDevicesExtension();
    }

    #[Test]
    public function nameReturnsPulsarMedicalDevices(): void
    {
        self::assertSame('pulsar/medical-devices', $this->extension->name());
    }

    #[Test]
    public function registerDoesNotThrow(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $this->extension->register($container);

        self::assertTrue(true, 'register() completed without exception');
    }

    #[Test]
    public function bootDoesNotRegisterRoutes(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::never())->method(self::anything());

        $this->extension->boot($container, $router);
    }

    #[Test]
    public function providersReturnsMedicalDevicesServiceProvider(): void
    {
        $providers = $this->extension->providers();

        self::assertCount(1, $providers);
        self::assertSame(MedicalDevicesServiceProvider::class, $providers[0]);
    }
}
