<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Devices;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Devices\DevicesExtension;
use Pulsar\Extension\Devices\DevicesServiceProvider;
use Pulsar\Routing\RouterInterface;

final class DevicesExtensionTest extends TestCase
{
    private DevicesExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new DevicesExtension();
    }

    #[Test]
    public function nameReturnsPulsarDevices(): void
    {
        self::assertSame('pulsar/devices', $this->extension->name());
    }

    #[Test]
    public function providersReturnsDevicesServiceProvider(): void
    {
        $providers = $this->extension->providers();

        self::assertCount(1, $providers);
        self::assertSame(DevicesServiceProvider::class, $providers[0]);
    }

    #[Test]
    public function registerIsNoOp(): void
    {
        $container = $this->createStub(ContainerInterface::class);

        $this->extension->register($container);

        self::assertSame('pulsar/devices', $this->extension->name());
    }

    #[Test]
    public function bootRegistersFourRoutes(): void
    {
        $container = $this->createStub(ContainerInterface::class);

        /** @var RouterInterface&MockObject $router */
        $router = $this->createMock(RouterInterface::class);

        // index (GET), register (POST), rotate (POST), delete (DELETE)
        $router->expects(self::once())
            ->method('get')
            ->willReturnSelf();

        $router->expects(self::exactly(2))
            ->method('post')
            ->willReturnSelf();

        $router->expects(self::once())
            ->method('delete')
            ->willReturnSelf();

        $this->extension->boot($container, $router);
    }

    #[Test]
    public function bootRegistersCorrectRoutePaths(): void
    {
        $container = $this->createStub(ContainerInterface::class);

        $getCalls = [];
        $postCalls = [];
        $deleteCalls = [];

        /** @var RouterInterface&MockObject $router */
        $router = $this->createMock(RouterInterface::class);

        $router->expects(self::once())
            ->method('get')
            ->willReturnCallback(function (string $path, mixed $handler, ?string $name = null) use ($router, &$getCalls): RouterInterface {
                $getCalls[] = ['path' => $path, 'name' => $name];

                return $router;
            });

        $router->expects(self::exactly(2))
            ->method('post')
            ->willReturnCallback(function (string $path, mixed $handler, ?string $name = null) use ($router, &$postCalls): RouterInterface {
                $postCalls[] = ['path' => $path, 'name' => $name];

                return $router;
            });

        $router->expects(self::once())
            ->method('delete')
            ->willReturnCallback(function (string $path, mixed $handler, ?string $name = null) use ($router, &$deleteCalls): RouterInterface {
                $deleteCalls[] = ['path' => $path, 'name' => $name];

                return $router;
            });

        $this->extension->boot($container, $router);

        self::assertSame('/api/v1/devices', $getCalls[0]['path']);
        self::assertSame('devices.api.index', $getCalls[0]['name']);

        self::assertSame('/api/v1/devices', $postCalls[0]['path']);
        self::assertSame('devices.api.register', $postCalls[0]['name']);

        self::assertSame('/api/v1/devices/{id}/rotate', $postCalls[1]['path']);
        self::assertSame('devices.api.rotate', $postCalls[1]['name']);

        self::assertSame('/api/v1/devices/{id}', $deleteCalls[0]['path']);
        self::assertSame('devices.api.delete', $deleteCalls[0]['name']);
    }
}
