<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Devices;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
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
    private UserDeviceRepositoryInterface&Stub $repo;
    private DeviceService $service;

    protected function setUp(): void
    {
        $this->repo = $this->createStub(UserDeviceRepositoryInterface::class);
        $this->service = new DeviceService($this->repo, maxDevicesPerUser: 3);
    }

    #[Test]
    public function registerCreatesDeviceAndReturnsToken(): void
    {
        $this->repo->method('countByUser')->willReturn(0);

        $result = $this->service->register(
            userId: 'user-001',
            deviceName: 'iPhone 15',
            platform: Platform::iOS,
            appVersion: '2.0.0',
        );

        self::assertArrayHasKey('device', $result);
        self::assertArrayHasKey('token', $result);
        self::assertInstanceOf(UserDevice::class, $result['device']);
        self::assertSame('user-001', $result['device']->userId);
        self::assertSame('iPhone 15', $result['device']->deviceName);
        self::assertSame(Platform::iOS, $result['device']->platform);
        self::assertSame('2.0.0', $result['device']->appVersion);
        // Raw token is 128 hex chars (64 random bytes)
        self::assertSame(128, strlen($result['token']));
    }

    #[Test]
    public function registerThrowsWhenAtMaxDeviceLimit(): void
    {
        $this->repo->method('countByUser')->willReturn(3);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Device limit reached');

        $this->service->register('user-001', 'New Device', Platform::Android, '1.0.0');
    }

    #[Test]
    public function registerThrowsWhenOverMaxDeviceLimit(): void
    {
        $this->repo->method('countByUser')->willReturn(5);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Device limit reached');

        $this->service->register('user-001', 'New Device', Platform::Web, '1.0.0');
    }

    #[Test]
    public function removeDeletesDeviceOwnedByUser(): void
    {
        $device = self::makeDevice('device-001', 'user-001');

        /** @var UserDeviceRepositoryInterface&MockObject $repo */
        $repo = $this->createMock(UserDeviceRepositoryInterface::class);
        $repo->method('findById')->willReturn($device);
        $repo->expects(self::once())->method('delete')->with('device-001');

        $service = new DeviceService($repo, maxDevicesPerUser: 3);
        $service->remove('device-001', 'user-001');
    }

    #[Test]
    public function removeThrowsWhenDeviceNotFound(): void
    {
        $this->repo->method('findById')->willReturn(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('not found');

        $this->service->remove('missing-device', 'user-001');
    }

    #[Test]
    public function removeThrowsWhenDeviceBelongsToAnotherUser(): void
    {
        $device = self::makeDevice('device-001', 'user-other');
        $this->repo->method('findById')->willReturn($device);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('not found or does not belong');

        $this->service->remove('device-001', 'user-001');
    }

    #[Test]
    public function rotateTokenReturnsNewToken(): void
    {
        $device = self::makeDevice('device-001', 'user-001');
        $this->repo->method('findById')->willReturn($device);

        $result = $this->service->rotateToken('device-001', 'user-001');

        self::assertArrayHasKey('device', $result);
        self::assertArrayHasKey('token', $result);
        self::assertSame(128, strlen($result['token']));
        // New token hash should differ from original
        self::assertNotSame($device->apiTokenHash, $result['device']->apiTokenHash);
    }

    #[Test]
    public function rotateTokenThrowsForWrongUser(): void
    {
        $device = self::makeDevice('device-001', 'user-other');
        $this->repo->method('findById')->willReturn($device);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('not found or does not belong');

        $this->service->rotateToken('device-001', 'user-001');
    }

    #[Test]
    public function rotateTokenThrowsForMissingDevice(): void
    {
        $this->repo->method('findById')->willReturn(null);

        $this->expectException(RuntimeException::class);

        $this->service->rotateToken('missing', 'user-001');
    }

    #[Test]
    public function listDevicesReturnsUserDevices(): void
    {
        $device1 = self::makeDevice('device-001', 'user-001');
        $device2 = self::makeDevice('device-002', 'user-001');

        $this->repo->method('findByUser')->willReturn([$device1, $device2]);

        $devices = $this->service->listDevices('user-001');

        self::assertCount(2, $devices);
        self::assertSame('device-001', $devices[0]->id);
        self::assertSame('device-002', $devices[1]->id);
    }

    #[Test]
    public function listDevicesReturnsEmptyForNoDevices(): void
    {
        $this->repo->method('findByUser')->willReturn([]);

        $devices = $this->service->listDevices('user-001');

        self::assertSame([], $devices);
    }

    #[Test]
    public function authenticateReturnsDeviceForValidToken(): void
    {
        // Generate a known token and its BLAKE2b hash
        $rawToken = bin2hex(random_bytes(64));
        $hash = bin2hex(sodium_crypto_generichash($rawToken));

        $device = self::makeDevice('device-001', 'user-001', $hash);
        $this->repo->method('findByTokenHash')->willReturn($device);

        $result = $this->service->authenticate($rawToken);

        self::assertNotNull($result);
        self::assertSame('user-001', $result->userId);
    }

    #[Test]
    public function authenticateReturnsNullForInvalidToken(): void
    {
        $this->repo->method('findByTokenHash')->willReturn(null);

        $result = $this->service->authenticate('invalid-token-string');

        self::assertNull($result);
    }

    private static function makeDevice(string $id, string $userId, string $hash = 'abcdef1234'): UserDevice
    {
        return new UserDevice(
            id: $id,
            userId: $userId,
            deviceName: 'Test Device',
            platform: Platform::Android,
            appVersion: '1.0.0',
            apiTokenHash: $hash,
            lastSeenAt: null,
            createdAt: new DateTimeImmutable(),
        );
    }
}
