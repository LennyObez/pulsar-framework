<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Devices;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Extension\Devices\Internal\Persistence\DbUserDeviceRepository;
use Pulsar\Extension\Devices\Platform;
use Pulsar\Extension\Devices\UserDevice;

#[CoversClass(DbUserDeviceRepository::class)]
final class DbUserDeviceRepositoryTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function deviceRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 'dev-001',
            'user_id' => 'user-1',
            'device_name' => 'My Phone',
            'platform' => 'android',
            'app_version' => '2.0.0',
            'api_token_hash' => 'hash123',
            'last_seen_at' => '2026-01-15T10:00:00+00:00',
            'created_at' => '2026-01-15T10:00:00+00:00',
        ], $overrides);
    }

    // ── save ─────────────────────────────────────────────────────────

    #[Test]
    public function saveCallsExecuteWithUpsertQuery(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->expects($this->once())->method('execute');

        $device = new UserDevice(
            id: 'dev-001',
            userId: 'user-1',
            deviceName: 'My Phone',
            platform: Platform::Android,
            appVersion: '2.0.0',
            apiTokenHash: 'hash123',
            lastSeenAt: new DateTimeImmutable('2026-01-15T10:00:00+00:00'),
            createdAt: new DateTimeImmutable('2026-01-15T10:00:00+00:00'),
        );

        $repo = new DbUserDeviceRepository($db);
        $repo->save($device);
    }

    // ── findById ─────────────────────────────────────────────────────

    #[Test]
    public function findByIdReturnsDeviceWhenFound(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturn(Result::fromArrays([$this->deviceRow()]));

        $repo = new DbUserDeviceRepository($db);
        $result = $repo->findById('dev-001');

        self::assertInstanceOf(UserDevice::class, $result);
        self::assertSame('dev-001', $result->id);
        self::assertSame('user-1', $result->userId);
        self::assertSame('My Phone', $result->deviceName);
        self::assertSame(Platform::Android, $result->platform);
        self::assertSame('2.0.0', $result->appVersion);
        self::assertSame('hash123', $result->apiTokenHash);
        self::assertSame('2026-01-15T10:00:00+00:00', $result->lastSeenAt?->format('c'));
        self::assertSame('2026-01-15T10:00:00+00:00', $result->createdAt->format('c'));
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturn(Result::fromArrays([]));

        $repo = new DbUserDeviceRepository($db);

        self::assertNull($repo->findById('nonexistent'));
    }

    #[Test]
    public function findByIdHydratesNullLastSeenAt(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturn(Result::fromArrays([
            $this->deviceRow(['last_seen_at' => null]),
        ]));

        $repo = new DbUserDeviceRepository($db);
        $result = $repo->findById('dev-001');

        self::assertInstanceOf(UserDevice::class, $result);
        self::assertNull($result->lastSeenAt);
    }

    // ── findByUser ───────────────────────────────────────────────────

    #[Test]
    public function findByUserReturnsListOfDevices(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturn(Result::fromArrays([
            $this->deviceRow(['id' => 'dev-001']),
            $this->deviceRow(['id' => 'dev-002', 'device_name' => 'My Tablet', 'platform' => 'ios']),
        ]));

        $repo = new DbUserDeviceRepository($db);
        $result = $repo->findByUser('user-1');

        self::assertCount(2, $result);
        self::assertSame('dev-001', $result[0]->id);
        self::assertSame('dev-002', $result[1]->id);
        self::assertSame(Platform::iOS, $result[1]->platform);
    }

    #[Test]
    public function findByUserReturnsEmptyArrayWhenNoneExist(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturn(Result::fromArrays([]));

        $repo = new DbUserDeviceRepository($db);

        self::assertSame([], $repo->findByUser('user-1'));
    }

    // ── findByTokenHash ──────────────────────────────────────────────

    #[Test]
    public function findByTokenHashReturnsDeviceWhenFound(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturn(Result::fromArrays([$this->deviceRow()]));

        $repo = new DbUserDeviceRepository($db);
        $result = $repo->findByTokenHash('hash123');

        self::assertInstanceOf(UserDevice::class, $result);
        self::assertSame('hash123', $result->apiTokenHash);
    }

    #[Test]
    public function findByTokenHashReturnsNullWhenNotFound(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturn(Result::fromArrays([]));

        $repo = new DbUserDeviceRepository($db);

        self::assertNull($repo->findByTokenHash('nonexistent'));
    }

    // ── delete ───────────────────────────────────────────────────────

    #[Test]
    public function deleteExecutesDeleteStatement(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->expects($this->once())->method('execute');

        $repo = new DbUserDeviceRepository($db);
        $repo->delete('dev-001');
    }

    // ── countByUser ──────────────────────────────────────────────────

    #[Test]
    public function countByUserReturnsCount(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturn(Result::fromArrays([['total' => 5]]));

        $repo = new DbUserDeviceRepository($db);

        self::assertSame(5, $repo->countByUser('user-1'));
    }

    #[Test]
    public function countByUserReturnsZeroWhenNoRows(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturn(Result::fromArrays([]));

        $repo = new DbUserDeviceRepository($db);

        self::assertSame(0, $repo->countByUser('user-1'));
    }
}
