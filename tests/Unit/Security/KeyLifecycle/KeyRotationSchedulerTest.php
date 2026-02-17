<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\KeyLifecycle;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\KeyLifecycle\KeyInventory;
use Pulsar\Security\KeyLifecycle\KeyInventoryEntry;
use Pulsar\Security\KeyLifecycle\KeyRotationScheduler;
use Pulsar\Security\KeyLifecycle\KeyType;

#[CoversClass(KeyRotationScheduler::class)]
final class KeyRotationSchedulerTest extends TestCase
{
    public function testScheduleAndGetSchedule(): void
    {
        $inventory = new KeyInventory();
        $scheduler = new KeyRotationScheduler($inventory);

        $scheduler->schedule('key-1', 86400);

        $schedule = $scheduler->getSchedule('key-1');
        self::assertNotNull($schedule);
        self::assertSame('key-1', $schedule->kid);
        self::assertSame(86400, $schedule->intervalSeconds);
    }

    public function testScheduleUsesDefaultGracePeriod(): void
    {
        $inventory = new KeyInventory();
        $scheduler = new KeyRotationScheduler($inventory, defaultGracePeriodSeconds: 7200);

        $scheduler->schedule('key-1', 86400);

        $schedule = $scheduler->getSchedule('key-1');
        self::assertNotNull($schedule);
        self::assertSame(7200, $schedule->gracePeriodSeconds);
    }

    public function testScheduleWithCustomGracePeriod(): void
    {
        $inventory = new KeyInventory();
        $scheduler = new KeyRotationScheduler($inventory);

        $scheduler->schedule('key-1', 86400, gracePeriodSeconds: 3600);

        $schedule = $scheduler->getSchedule('key-1');
        self::assertNotNull($schedule);
        self::assertSame(3600, $schedule->gracePeriodSeconds);
    }

    public function testUnschedule(): void
    {
        $inventory = new KeyInventory();
        $scheduler = new KeyRotationScheduler($inventory);

        $scheduler->schedule('key-1', 86400);
        $scheduler->unschedule('key-1');

        self::assertNull($scheduler->getSchedule('key-1'));
    }

    public function testDueForRotation(): void
    {
        $now = new DateTimeImmutable();
        $inventory = new KeyInventory();

        // Key created 2 hours ago, rotation interval = 1 hour
        $entry = new KeyInventoryEntry(
            kid: 'due-key',
            type: KeyType::Encryption,
            createdAt: $now->modify('-2 hours'),
            lastRotatedAt: null,
            rotationIntervalSeconds: 3600,
            active: true,
            algorithm: 'aes-256-gcm',
            context: 'test',
        );
        $inventory->register($entry);

        $scheduler = new KeyRotationScheduler($inventory);
        $scheduler->schedule('due-key', 3600);

        $due = $scheduler->dueForRotation($now);
        self::assertCount(1, $due);
        self::assertSame('due-key', $due[0]->kid);
    }

    public function testDueForRotationExcludesInactiveKeys(): void
    {
        $now = new DateTimeImmutable();
        $inventory = new KeyInventory();

        $entry = new KeyInventoryEntry(
            kid: 'inactive-key',
            type: KeyType::Encryption,
            createdAt: $now->modify('-2 hours'),
            lastRotatedAt: null,
            rotationIntervalSeconds: 3600,
            active: false,
            algorithm: 'aes-256-gcm',
            context: 'test',
        );
        $inventory->register($entry);

        $scheduler = new KeyRotationScheduler($inventory);
        $scheduler->schedule('inactive-key', 3600);

        self::assertSame([], $scheduler->dueForRotation($now));
    }

    public function testIsInGracePeriod(): void
    {
        $now = new DateTimeImmutable();
        $inventory = new KeyInventory();

        // Key rotated 30 minutes ago, grace period = 1 hour
        $entry = new KeyInventoryEntry(
            kid: 'recent-key',
            type: KeyType::Encryption,
            createdAt: $now->modify('-24 hours'),
            lastRotatedAt: $now->modify('-30 minutes'),
            rotationIntervalSeconds: 86400,
            active: true,
            algorithm: 'aes-256-gcm',
            context: 'test',
        );
        $inventory->register($entry);

        $scheduler = new KeyRotationScheduler($inventory);
        $scheduler->schedule('recent-key', 86400, gracePeriodSeconds: 3600);

        self::assertTrue($scheduler->isInGracePeriod('recent-key', $now));
    }

    public function testIsNotInGracePeriodAfterExpiry(): void
    {
        $now = new DateTimeImmutable();
        $inventory = new KeyInventory();

        // Key rotated 2 hours ago, grace period = 1 hour
        $entry = new KeyInventoryEntry(
            kid: 'old-key',
            type: KeyType::Encryption,
            createdAt: $now->modify('-24 hours'),
            lastRotatedAt: $now->modify('-2 hours'),
            rotationIntervalSeconds: 86400,
            active: true,
            algorithm: 'aes-256-gcm',
            context: 'test',
        );
        $inventory->register($entry);

        $scheduler = new KeyRotationScheduler($inventory);
        $scheduler->schedule('old-key', 86400, gracePeriodSeconds: 3600);

        self::assertFalse($scheduler->isInGracePeriod('old-key', $now));
    }

    public function testIsInGracePeriodReturnsFalseForUnscheduled(): void
    {
        $inventory = new KeyInventory();
        $scheduler = new KeyRotationScheduler($inventory);

        self::assertFalse($scheduler->isInGracePeriod('nonexistent'));
    }

    public function testAllSchedules(): void
    {
        $inventory = new KeyInventory();
        $scheduler = new KeyRotationScheduler($inventory);

        $scheduler->schedule('key-1', 86400);
        $scheduler->schedule('key-2', 3600);

        self::assertCount(2, $scheduler->allSchedules());
    }
}
