<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Pool;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Pool\PooledEntry;
use ReflectionClass;

#[CoversClass(PooledEntry::class)]
final class PooledEntryTest extends TestCase
{
    #[Test]
    public function constructsWithConnectionAndTimestamps(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $createdAt = 1700000000;
        $lastUsedAt = 1700000100;

        $entry = new PooledEntry(
            connection: $connection,
            createdAt: $createdAt,
            lastUsedAt: $lastUsedAt,
        );

        self::assertSame($connection, $entry->connection);
        self::assertSame($createdAt, $entry->createdAt);
        self::assertSame($lastUsedAt, $entry->lastUsedAt);
    }

    #[Test]
    public function createdAtMatchesLastUsedAtForNewEntry(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $now = time();

        $entry = new PooledEntry(
            connection: $connection,
            createdAt: $now,
            lastUsedAt: $now,
        );

        self::assertSame($entry->createdAt, $entry->lastUsedAt);
    }

    #[Test]
    public function lastUsedAtCanBeAfterCreatedAt(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);

        $entry = new PooledEntry(
            connection: $connection,
            createdAt: 1000,
            lastUsedAt: 2000,
        );

        self::assertGreaterThan($entry->createdAt, $entry->lastUsedAt);
    }

    #[Test]
    public function isReadonly(): void
    {
        $entry = new PooledEntry(
            connection: $this->createStub(ConnectionInterface::class),
            createdAt: 0,
            lastUsedAt: 0,
        );

        $reflection = new ReflectionClass($entry);
        self::assertTrue($reflection->isReadOnly());
    }
}
