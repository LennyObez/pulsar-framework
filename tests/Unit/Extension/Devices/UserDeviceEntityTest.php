<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Devices;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Devices\Platform;
use Pulsar\Extension\Devices\UserDevice;

use function strlen;

final class UserDeviceEntityTest extends TestCase
{
    #[Test]
    public function createGeneratesIdAndSetsDefaults(): void
    {
        $device = UserDevice::create(
            userId: 'user-1',
            deviceName: 'iPhone 15',
            platform: Platform::iOS,
            appVersion: '2.0.0',
            apiTokenHash: 'blake2b-hash',
        );

        self::assertSame(32, strlen($device->id));
        self::assertSame('user-1', $device->userId);
        self::assertSame('iPhone 15', $device->deviceName);
        self::assertSame(Platform::iOS, $device->platform);
        self::assertSame('2.0.0', $device->appVersion);
        self::assertSame('blake2b-hash', $device->apiTokenHash);
        self::assertNull($device->lastSeenAt);
        self::assertInstanceOf(DateTimeImmutable::class, $device->createdAt);
    }

    #[Test]
    public function updateLastSeenSetsTimestamp(): void
    {
        $device = UserDevice::create(
            userId: 'user-1',
            deviceName: 'Phone',
            platform: Platform::Android,
            appVersion: '1.0.0',
            apiTokenHash: 'hash-abc',
        );

        self::assertNull($device->lastSeenAt);

        $updated = $device->updateLastSeen();

        self::assertNotNull($updated->lastSeenAt);
        self::assertInstanceOf(DateTimeImmutable::class, $updated->lastSeenAt);
        self::assertSame($device->id, $updated->id);
        self::assertSame($device->userId, $updated->userId);
        self::assertSame($device->apiTokenHash, $updated->apiTokenHash);
    }

    #[Test]
    public function rotateTokenReplacesHash(): void
    {
        $device = UserDevice::create(
            userId: 'user-1',
            deviceName: 'Tablet',
            platform: Platform::Android,
            appVersion: '1.5.0',
            apiTokenHash: 'old-hash',
        );

        $rotated = $device->rotateToken('new-blake2b-hash');

        self::assertSame('new-blake2b-hash', $rotated->apiTokenHash);
        self::assertSame($device->id, $rotated->id);
        self::assertSame($device->userId, $rotated->userId);
        self::assertSame($device->deviceName, $rotated->deviceName);
        self::assertSame($device->platform, $rotated->platform);
    }

    #[Test]
    public function constructorAcceptsAllParameters(): void
    {
        $createdAt = new DateTimeImmutable('2026-01-01');
        $lastSeen = new DateTimeImmutable('2026-03-10');

        $device = new UserDevice(
            id: 'dev-custom',
            userId: 'user-99',
            deviceName: 'Desktop',
            platform: Platform::Web,
            appVersion: '3.0.0',
            apiTokenHash: 'hash-xyz',
            lastSeenAt: $lastSeen,
            createdAt: $createdAt,
        );

        self::assertSame('dev-custom', $device->id);
        self::assertSame('user-99', $device->userId);
        self::assertSame('Desktop', $device->deviceName);
        self::assertSame(Platform::Web, $device->platform);
        self::assertSame('3.0.0', $device->appVersion);
        self::assertSame('hash-xyz', $device->apiTokenHash);
        self::assertSame($lastSeen, $device->lastSeenAt);
        self::assertSame($createdAt, $device->createdAt);
    }

    #[Test]
    public function createForEachPlatform(): void
    {
        foreach (Platform::cases() as $platform) {
            $device = UserDevice::create(
                userId: 'user-1',
                deviceName: "Device-{$platform->value}",
                platform: $platform,
                appVersion: '1.0.0',
                apiTokenHash: 'hash-' . $platform->value,
            );

            self::assertSame($platform, $device->platform);
        }
    }
}
