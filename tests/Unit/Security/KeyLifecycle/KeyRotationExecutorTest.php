<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\KeyLifecycle;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\KeyLifecycle\KeyInventory;
use Pulsar\Security\KeyLifecycle\KeyInventoryEntry;
use Pulsar\Security\KeyLifecycle\KeyRotationExecutor;
use Pulsar\Security\KeyLifecycle\KeyType;

#[CoversClass(KeyRotationExecutor::class)]
final class KeyRotationExecutorTest extends TestCase
{
    public function testRotateCreatesNewKeyAndDeactivatesOld(): void
    {
        $inventory = new KeyInventory();
        $now = new DateTimeImmutable();

        $entry = new KeyInventoryEntry(
            kid: 'old-key',
            type: KeyType::Encryption,
            createdAt: $now->modify('-24 hours'),
            lastRotatedAt: null,
            rotationIntervalSeconds: 86400,
            active: true,
            algorithm: 'aes-256-gcm',
            context: 'test',
        );
        $inventory->register($entry);

        $executor = new KeyRotationExecutor($inventory);
        $result = $executor->rotate($entry);

        self::assertTrue($result->success);
        self::assertSame(KeyType::Encryption, $result->keyType);
        self::assertSame('old-key', $result->previousKid);
        self::assertNotSame('old-key', $result->kid);

        // Old key should be deactivated
        $oldEntry = $inventory->find('old-key');
        self::assertNotNull($oldEntry);
        self::assertFalse($oldEntry->active);

        // New key should be active
        $newEntry = $inventory->find($result->kid);
        self::assertNotNull($newEntry);
        self::assertTrue($newEntry->active);
        self::assertSame('aes-256-gcm', $newEntry->algorithm);
        self::assertSame('test', $newEntry->context);
    }

    public function testRotatePreservesKeyType(): void
    {
        $inventory = new KeyInventory();

        $entry = new KeyInventoryEntry(
            kid: 'sign-key',
            type: KeyType::Signing,
            createdAt: new DateTimeImmutable('-1 day'),
            lastRotatedAt: null,
            rotationIntervalSeconds: 86400,
            active: true,
            algorithm: 'ed25519',
            context: 'artifact signing',
        );
        $inventory->register($entry);

        $executor = new KeyRotationExecutor($inventory);
        $result = $executor->rotate($entry, 'manual');

        self::assertSame(KeyType::Signing, $result->keyType);
        self::assertSame('manual', $result->reason);
    }

    public function testRotateAllProcessesDueKeys(): void
    {
        $inventory = new KeyInventory();
        $now = new DateTimeImmutable();

        // Due for rotation
        $entry1 = new KeyInventoryEntry(
            kid: 'due-1',
            type: KeyType::Encryption,
            createdAt: $now->modify('-2 hours'),
            lastRotatedAt: null,
            rotationIntervalSeconds: 3600,
            active: true,
            algorithm: 'aes-256-gcm',
            context: 'test',
        );

        // Not due
        $entry2 = new KeyInventoryEntry(
            kid: 'fresh',
            type: KeyType::Encryption,
            createdAt: $now,
            lastRotatedAt: null,
            rotationIntervalSeconds: 3600,
            active: true,
            algorithm: 'aes-256-gcm',
            context: 'test',
        );

        $inventory->register($entry1);
        $inventory->register($entry2);

        $scheduler = new \Pulsar\Security\KeyLifecycle\KeyRotationScheduler($inventory);
        $scheduler->schedule('due-1', 3600);
        $scheduler->schedule('fresh', 3600);

        $executor = new KeyRotationExecutor($inventory);
        $results = $executor->rotateAll($scheduler);

        self::assertCount(1, $results);
        self::assertTrue($results[0]->success);
        self::assertSame('due-1', $results[0]->previousKid);
    }
}
