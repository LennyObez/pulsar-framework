<?php

declare(strict_types=1);

namespace Pulsar\Extension\Devices\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Devices\Platform;
use Pulsar\Extension\Devices\UserDevice;

use function strlen;

final class UserDeviceTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $now = new DateTimeImmutable('2026-01-01 12:00:00');
        $lastSeen = new DateTimeImmutable('2026-01-02 08:00:00');

        $device = new UserDevice(
            id: 'device-abc',
            userId: 'user-1',
            deviceName: 'My Phone',
            platform: Platform::iOS,
            appVersion: '2.1.0',
            apiTokenHash: 'hash123',
            lastSeenAt: $lastSeen,
            createdAt: $now,
        );

        self::assertSame('device-abc', $device->id);
        self::assertSame('user-1', $device->userId);
        self::assertSame('My Phone', $device->deviceName);
        self::assertSame(Platform::iOS, $device->platform);
        self::assertSame('2.1.0', $device->appVersion);
        self::assertSame('hash123', $device->apiTokenHash);
        self::assertSame($lastSeen, $device->lastSeenAt);
        self::assertSame($now, $device->createdAt);
    }

    #[Test]
    public function createGeneratesIdAndSetsDefaults(): void
    {
        $device = UserDevice::create(
            userId: 'user-42',
            deviceName: 'Work Laptop',
            platform: Platform::Web,
            appVersion: '1.0.0',
            apiTokenHash: 'testhash',
        );

        self::assertSame(32, strlen($device->id));
        self::assertSame('user-42', $device->userId);
        self::assertSame('Work Laptop', $device->deviceName);
        self::assertSame(Platform::Web, $device->platform);
        self::assertSame('1.0.0', $device->appVersion);
        self::assertSame('testhash', $device->apiTokenHash);
        self::assertNull($device->lastSeenAt);
        self::assertInstanceOf(DateTimeImmutable::class, $device->createdAt);
    }

    #[Test]
    public function createGeneratesUniqueIds(): void
    {
        $d1 = UserDevice::create('u1', 'Phone', Platform::Android, '1.0', 'h1');
        $d2 = UserDevice::create('u1', 'Tablet', Platform::Android, '1.0', 'h2');

        self::assertNotSame($d1->id, $d2->id);
    }

    #[Test]
    public function updateLastSeenReturnsNewInstanceWithTimestamp(): void
    {
        $device = UserDevice::create('u1', 'Phone', Platform::iOS, '1.0', 'hash');
        self::assertNull($device->lastSeenAt);

        $updated = $device->updateLastSeen();

        self::assertNotNull($updated->lastSeenAt);
        self::assertNull($device->lastSeenAt);
        self::assertSame($device->id, $updated->id);
        self::assertSame($device->apiTokenHash, $updated->apiTokenHash);
    }

    #[Test]
    public function rotateTokenReturnsNewInstanceWithNewHash(): void
    {
        $device = UserDevice::create('u1', 'Phone', Platform::Android, '1.0', 'oldhash');

        $updated = $device->rotateToken('newhash');

        self::assertSame('newhash', $updated->apiTokenHash);
        self::assertSame('oldhash', $device->apiTokenHash);
        self::assertSame($device->id, $updated->id);
        self::assertSame($device->userId, $updated->userId);
    }
}
