<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\KeyLifecycle;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\KeyLifecycle\KeyInventoryEntry;
use Pulsar\Security\KeyLifecycle\KeyType;

#[CoversClass(KeyInventoryEntry::class)]
final class KeyInventoryEntryTest extends TestCase
{
    public function testIsDueForRotationWhenIntervalZero(): void
    {
        $entry = $this->createEntry(rotationIntervalSeconds: 0);
        self::assertFalse($entry->isDueForRotation());
    }

    public function testIsDueForRotationWhenElapsed(): void
    {
        $now = new DateTimeImmutable();
        $entry = new KeyInventoryEntry(
            kid: 'test',
            type: KeyType::Encryption,
            createdAt: $now->modify('-2 hours'),
            lastRotatedAt: null,
            rotationIntervalSeconds: 3600,
            active: true,
            algorithm: 'aes-256-gcm',
            context: 'test',
        );

        self::assertTrue($entry->isDueForRotation($now));
    }

    public function testIsDueForRotationUsesLastRotatedAt(): void
    {
        $now = new DateTimeImmutable();
        $entry = new KeyInventoryEntry(
            kid: 'test',
            type: KeyType::Encryption,
            createdAt: $now->modify('-10 hours'),
            lastRotatedAt: $now->modify('-30 minutes'),
            rotationIntervalSeconds: 3600,
            active: true,
            algorithm: 'aes-256-gcm',
            context: 'test',
        );

        // Created 10h ago but rotated 30min ago — not due
        self::assertFalse($entry->isDueForRotation($now));
    }

    public function testSecondsUntilRotation(): void
    {
        $now = new DateTimeImmutable();
        $entry = new KeyInventoryEntry(
            kid: 'test',
            type: KeyType::Encryption,
            createdAt: $now->modify('-1800 seconds'),
            lastRotatedAt: null,
            rotationIntervalSeconds: 3600,
            active: true,
            algorithm: 'aes-256-gcm',
            context: 'test',
        );

        $remaining = $entry->secondsUntilRotation($now);
        self::assertSame(1800, $remaining);
    }

    public function testSecondsUntilRotationReturnsZeroWhenDue(): void
    {
        $now = new DateTimeImmutable();
        $entry = new KeyInventoryEntry(
            kid: 'test',
            type: KeyType::Encryption,
            createdAt: $now->modify('-2 hours'),
            lastRotatedAt: null,
            rotationIntervalSeconds: 3600,
            active: true,
            algorithm: 'aes-256-gcm',
            context: 'test',
        );

        self::assertSame(0, $entry->secondsUntilRotation($now));
    }

    public function testSecondsUntilRotationReturnsZeroWhenDisabled(): void
    {
        $entry = $this->createEntry(rotationIntervalSeconds: 0);
        self::assertSame(0, $entry->secondsUntilRotation());
    }

    public function testToArray(): void
    {
        $now = new DateTimeImmutable('2026-01-15T10:00:00+00:00');
        $entry = new KeyInventoryEntry(
            kid: 'test-key',
            type: KeyType::Signing,
            createdAt: $now,
            lastRotatedAt: null,
            rotationIntervalSeconds: 86400,
            active: true,
            algorithm: 'ed25519',
            context: 'signing',
        );

        $array = $entry->toArray();
        self::assertSame('test-key', $array['kid']);
        self::assertSame('signing', $array['type']);
        self::assertNull($array['last_rotated_at']);
        self::assertSame(86400, $array['rotation_interval_seconds']);
        self::assertTrue($array['active']);
    }

    private function createEntry(int $rotationIntervalSeconds = 3600): KeyInventoryEntry
    {
        return new KeyInventoryEntry(
            kid: 'test',
            type: KeyType::Encryption,
            createdAt: new DateTimeImmutable(),
            lastRotatedAt: null,
            rotationIntervalSeconds: $rotationIntervalSeconds,
            active: true,
            algorithm: 'aes-256-gcm',
            context: 'test',
        );
    }
}
