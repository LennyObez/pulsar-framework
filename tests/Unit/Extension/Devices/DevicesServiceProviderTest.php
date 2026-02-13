<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Devices;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Devices\DevicesServiceProvider;
use Pulsar\Extension\Devices\Http\Controller\DeviceController;
use Pulsar\Extension\Devices\Http\Middleware\DeviceTokenGuard;
use Pulsar\Extension\Devices\Internal\DeviceService;
use Pulsar\Extension\Devices\UserDeviceRepositoryInterface;

final class DevicesServiceProviderTest extends TestCase
{
    private DevicesServiceProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new DevicesServiceProvider();
    }

    #[Test]
    public function providesReturnsFourClassStrings(): void
    {
        $provides = $this->provider->provides();

        self::assertCount(4, $provides);
    }

    #[Test]
    public function providesContainsExpectedClasses(): void
    {
        $provides = $this->provider->provides();

        self::assertContains(UserDeviceRepositoryInterface::class, $provides);
        self::assertContains(DeviceService::class, $provides);
        self::assertContains(DeviceController::class, $provides);
        self::assertContains(DeviceTokenGuard::class, $provides);
    }

    #[Test]
    public function registerReturnsEarlyWhenNoConnectionInterface(): void
    {
        /** @var ContainerInterface&MockObject $container */
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')
            ->willReturnCallback(static fn(string $id): bool => match ($id) {
                ConnectionInterface::class => false,
                default => false,
            });

        $container->expects(self::never())
            ->method('instance');

        $this->provider->register($container);
    }

    #[Test]
    public function registerBindsAllServicesWhenConnectionAvailable(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);

        $boundIds = [];

        /** @var ContainerInterface&MockObject $container */
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')
            ->willReturnCallback(static fn(string $id): bool => match ($id) {
                ConnectionInterface::class => true,
                default => false,
            });

        $container->method('get')
            ->willReturnCallback(static fn(string $id): mixed => match ($id) {
                ConnectionInterface::class => $connection,
                default => null,
            });

        // UserDeviceRepositoryInterface, DeviceService, DeviceController, DeviceTokenGuard
        $container->expects(self::exactly(4))
            ->method('instance')
            ->willReturnCallback(function (string $id, object $instance) use (&$boundIds): void {
                $boundIds[] = $id;
            });

        $this->provider->register($container);

        self::assertContains(UserDeviceRepositoryInterface::class, $boundIds);
        self::assertContains(DeviceService::class, $boundIds);
        self::assertContains(DeviceController::class, $boundIds);
        self::assertContains(DeviceTokenGuard::class, $boundIds);
    }
}
