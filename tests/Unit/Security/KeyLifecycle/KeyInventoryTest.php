<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\KeyLifecycle;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\KeyLifecycle\KeyInventory;
use Pulsar\Security\KeyLifecycle\KeyInventoryEntry;
use Pulsar\Security\KeyLifecycle\KeyType;

#[CoversClass(KeyInventory::class)]
final class KeyInventoryTest extends TestCase
{
    public function testRegisterAndFind(): void
    {
        $inventory = new KeyInventory();
        $entry = $this->createEntry('key-1', KeyType::Encryption);

        $inventory->register($entry);

        self::assertSame($entry, $inventory->find('key-1'));
    }

    public function testFindReturnsNullForUnknownKey(): void
    {
        $inventory = new KeyInventory();
        self::assertNull($inventory->find('nonexistent'));
    }

    public function testDeregister(): void
    {
        $inventory = new KeyInventory();
        $entry = $this->createEntry('key-1', KeyType::Encryption);

        $inventory->register($entry);
        $inventory->deregister('key-1');

        self::assertNull($inventory->find('key-1'));
        self::assertSame(0, $inventory->count());
    }

    public function testAll(): void
    {
        $inventory = new KeyInventory();
        $inventory->register($this->createEntry('key-1', KeyType::Encryption));
        $inventory->register($this->createEntry('key-2', KeyType::Signing));

        $all = $inventory->all();
        self::assertCount(2, $all);
    }

    public function testByType(): void
    {
        $inventory = new KeyInventory();
        $inventory->register($this->createEntry('enc-1', KeyType::Encryption));
        $inventory->register($this->createEntry('enc-2', KeyType::Encryption));
        $inventory->register($this->createEntry('sign-1', KeyType::Signing));

        $encKeys = $inventory->byType(KeyType::Encryption);
        self::assertCount(2, $encKeys);

        $signKeys = $inventory->byType(KeyType::Signing);
        self::assertCount(1, $signKeys);

        $tlsKeys = $inventory->byType(KeyType::Tls);
        self::assertCount(0, $tlsKeys);
    }

    public function testByTypeExcludesInactive(): void
    {
        $inventory = new KeyInventory();
        $inventory->register($this->createEntry('enc-1', KeyType::Encryption, active: true));
        $inventory->register($this->createEntry('enc-2', KeyType::Encryption, active: false));

        $keys = $inventory->byType(KeyType::Encryption);
        self::assertCount(1, $keys);
        self::assertSame('enc-1', $keys[0]->kid);
    }

    public function testCount(): void
    {
        $inventory = new KeyInventory();
        self::assertSame(0, $inventory->count());

        $inventory->register($this->createEntry('key-1', KeyType::Encryption));
        self::assertSame(1, $inventory->count());
    }

    public function testHas(): void
    {
        $inventory = new KeyInventory();
        $inventory->register($this->createEntry('key-1', KeyType::Encryption));

        self::assertTrue($inventory->has('key-1'));
        self::assertFalse($inventory->has('key-2'));
    }

    public function testDueForRotation(): void
    {
        $inventory = new KeyInventory();
        $now = new DateTimeImmutable();

        // Key created 2 hours ago, rotation interval = 1 hour → due
        $due = new KeyInventoryEntry(
            kid: 'due-key',
            type: KeyType::Encryption,
            createdAt: $now->modify('-2 hours'),
            lastRotatedAt: null,
            rotationIntervalSeconds: 3600,
            active: true,
            algorithm: 'aes-256-gcm',
            context: 'test',
        );

        // Key created 30 min ago, rotation interval = 1 hour → not due
        $notDue = new KeyInventoryEntry(
            kid: 'fresh-key',
            type: KeyType::Encryption,
            createdAt: $now->modify('-30 minutes'),
            lastRotatedAt: null,
            rotationIntervalSeconds: 3600,
            active: true,
            algorithm: 'aes-256-gcm',
            context: 'test',
        );

        $inventory->register($due);
        $inventory->register($notDue);

        $dueKeys = $inventory->dueForRotation($now);
        self::assertCount(1, $dueKeys);
        self::assertSame('due-key', $dueKeys[0]->kid);
    }

    public function testExport(): void
    {
        $inventory = new KeyInventory();
        $inventory->register($this->createEntry('key-1', KeyType::Encryption));

        $exported = $inventory->export();
        self::assertCount(1, $exported);
        self::assertSame('key-1', $exported[0]['kid']);
        self::assertSame('encryption', $exported[0]['type']);
    }

    private function createEntry(string $kid, KeyType $type, bool $active = true): KeyInventoryEntry
    {
        return new KeyInventoryEntry(
            kid: $kid,
            type: $type,
            createdAt: new DateTimeImmutable(),
            lastRotatedAt: null,
            rotationIntervalSeconds: 86400,
            active: $active,
            algorithm: 'aes-256-gcm',
            context: 'test',
        );
    }
}
