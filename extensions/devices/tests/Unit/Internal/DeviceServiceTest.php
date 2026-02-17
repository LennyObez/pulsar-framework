<?php

declare(strict_types=1);

namespace Pulsar\Extension\Devices\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Devices\Internal\DeviceService;
use Pulsar\Extension\Devices\Platform;
use Pulsar\Extension\Devices\UserDevice;
use Pulsar\Extension\Devices\UserDeviceRepositoryInterface;
use RuntimeException;

use function strlen;

final class DeviceServiceTest extends TestCase
{
    private UserDeviceRepositoryInterface&Stub $repository;
    private DeviceService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createStub(UserDeviceRepositoryInterface::class);
        $this->service = new DeviceService($this->repository, maxDevicesPerUser: 3);
    }

    #[Test]
    public function listDevicesDelegatesToRepository(): void
    {
        $devices = [
            UserDevice::create('u1', 'Phone', Platform::iOS, '1.0', 'h1'),
            UserDevice::create('u1', 'Tablet', Platform::Android, '1.0', 'h2'),
        ];
        $this->repository->method('findByUser')->willReturn($devices);

        $result = $this->service->listDevices('u1');

        self::assertCount(2, $result);
    }

    #[Test]
    public function registerCreatesDeviceWithToken(): void
    {
        $this->repository->method('countByUser')->willReturn(0);

        $result = $this->service->register('u1', 'Phone', Platform::iOS, '2.0');

        self::assertArrayHasKey('device', $result);
        self::assertArrayHasKey('token', $result);
        self::assertInstanceOf(UserDevice::class, $result['device']);
        self::assertSame(128, strlen($result['token'])); // 64 bytes = 128 hex chars
        self::assertSame('u1', $result['device']->userId);
        self::assertSame('Phone', $result['device']->deviceName);
        self::assertSame(Platform::iOS, $result['device']->platform);
    }

    #[Test]
    public function registerThrowsWhenDeviceLimitReached(): void
    {
        $this->repository->method('countByUser')->willReturn(3);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Device limit reached');

        $this->service->register('u1', 'Phone', Platform::iOS, '1.0');
    }

    #[Test]
    public function registerAllowsUpToMaxMinusOneDevices(): void
    {
        $this->repository->method('countByUser')->willReturn(2);

        $result = $this->service->register('u1', 'Phone', Platform::Web, '1.0');

        self::assertInstanceOf(UserDevice::class, $result['device']);
    }

    #[Test]
    public function rotateTokenReturnsNewDeviceAndToken(): void
    {
        $device = UserDevice::create('u1', 'Phone', Platform::Android, '1.0', 'oldhash');
        $this->repository->method('findById')->willReturn($device);

        $result = $this->service->rotateToken($device->id, 'u1');

        self::assertArrayHasKey('device', $result);
        self::assertArrayHasKey('token', $result);
        self::assertNotSame('oldhash', $result['device']->apiTokenHash);
        self::assertSame(128, strlen($result['token']));
    }

    #[Test]
    public function rotateTokenThrowsWhenDeviceNotFound(): void
    {
        $this->repository->method('findById')->willReturn(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found');

        $this->service->rotateToken('nonexistent', 'u1');
    }

    #[Test]
    public function rotateTokenThrowsWhenDeviceBelongsToDifferentUser(): void
    {
        $device = UserDevice::create('u1', 'Phone', Platform::iOS, '1.0', 'hash');
        $this->repository->method('findById')->willReturn($device);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not belong to user');

        $this->service->rotateToken($device->id, 'u2');
    }

    #[Test]
    public function removeDeletesDevice(): void
    {
        $device = UserDevice::create('u1', 'Phone', Platform::iOS, '1.0', 'hash');
        $this->repository->method('findById')->willReturn($device);

        // Should not throw
        $this->service->remove($device->id, 'u1');

        self::assertTrue(true, 'remove() completed without exception');
    }

    #[Test]
    public function removeThrowsWhenDeviceNotFound(): void
    {
        $this->repository->method('findById')->willReturn(null);

        $this->expectException(RuntimeException::class);

        $this->service->remove('nonexistent', 'u1');
    }

    #[Test]
    public function removeThrowsWhenDeviceBelongsToDifferentUser(): void
    {
        $device = UserDevice::create('u1', 'Phone', Platform::iOS, '1.0', 'hash');
        $this->repository->method('findById')->willReturn($device);

        $this->expectException(RuntimeException::class);

        $this->service->remove($device->id, 'u2');
    }

    #[Test]
    public function authenticateReturnsDeviceOnValidToken(): void
    {
        $rawToken = bin2hex(random_bytes(64));
        $hash = bin2hex(sodium_crypto_generichash($rawToken));
        $device = UserDevice::create('u1', 'Phone', Platform::Android, '1.0', $hash);

        $this->repository->method('findByTokenHash')->willReturn($device);

        $result = $this->service->authenticate($rawToken);

        self::assertNotNull($result);
        self::assertSame($device->userId, $result->userId);
        self::assertNotNull($result->lastSeenAt);
    }

    #[Test]
    public function authenticateReturnsNullOnInvalidToken(): void
    {
        $this->repository->method('findByTokenHash')->willReturn(null);

        $result = $this->service->authenticate('invalidtoken');

        self::assertNull($result);
    }
}
