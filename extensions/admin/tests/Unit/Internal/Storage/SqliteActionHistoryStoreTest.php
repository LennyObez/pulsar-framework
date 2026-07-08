<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Storage;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryEntry;
use Pulsar\Extension\Admin\Internal\Storage\SqliteActionHistoryStore;

#[CoversClass(SqliteActionHistoryStore::class)]
final class SqliteActionHistoryStoreTest extends TestCase
{
    private SqliteActionHistoryStore $store;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->store = new SqliteActionHistoryStore($pdo);
    }

    #[Test]
    public function recordAndRetrieve(): void
    {
        $entry = new ActionHistoryEntry(
            id: 'test-001',
            action: 'create',
            resourceName: 'users',
            recordId: '42',
            actor: 'admin@example.com',
            timestamp: 1700000000,
            success: true,
            detail: 'User created',
        );

        $this->store->record($entry);

        $recent = $this->store->recent(10);
        self::assertCount(1, $recent);
        self::assertSame('test-001', $recent[0]->id);
        self::assertSame('create', $recent[0]->action);
        self::assertSame('users', $recent[0]->resourceName);
        self::assertSame('42', $recent[0]->recordId);
        self::assertSame('admin@example.com', $recent[0]->actor);
        self::assertSame(1700000000, $recent[0]->timestamp);
        self::assertTrue($recent[0]->success);
        self::assertSame('User created', $recent[0]->detail);
    }

    #[Test]
    public function recentRespectsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->store->record(new ActionHistoryEntry(
                id: "id-{$i}",
                action: 'update',
                resourceName: 'users',
                recordId: (string) $i,
                actor: 'admin',
                timestamp: $i,
                success: true,
                detail: '',
            ));
        }

        self::assertCount(3, $this->store->recent(3));
        self::assertCount(5, $this->store->recent(10));
    }

    #[Test]
    public function recentOrdersByTimestampDescending(): void
    {
        $this->store->record(new ActionHistoryEntry('a', 'create', 'users', '1', 'admin', 100, true, ''));
        $this->store->record(new ActionHistoryEntry('b', 'update', 'users', '2', 'admin', 300, true, ''));
        $this->store->record(new ActionHistoryEntry('c', 'delete', 'users', '3', 'admin', 200, true, ''));

        $recent = $this->store->recent(10);
        self::assertSame('b', $recent[0]->id);
        self::assertSame('c', $recent[1]->id);
        self::assertSame('a', $recent[2]->id);
    }

    #[Test]
    public function forResourceFilters(): void
    {
        $this->store->record(new ActionHistoryEntry('a', 'create', 'users', '1', 'admin', 100, true, ''));
        $this->store->record(new ActionHistoryEntry('b', 'create', 'orders', '2', 'admin', 200, true, ''));
        $this->store->record(new ActionHistoryEntry('c', 'update', 'users', '3', 'admin', 300, true, ''));

        $userEntries = $this->store->forResource('users');
        self::assertCount(2, $userEntries);
        self::assertSame('users', $userEntries[0]->resourceName);
        self::assertSame('users', $userEntries[1]->resourceName);

        $orderEntries = $this->store->forResource('orders');
        self::assertCount(1, $orderEntries);
        self::assertSame('orders', $orderEntries[0]->resourceName);
    }

    #[Test]
    public function forResourceRespectsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->store->record(new ActionHistoryEntry(
                id: "id-{$i}",
                action: 'update',
                resourceName: 'users',
                recordId: (string) $i,
                actor: 'admin',
                timestamp: $i,
                success: true,
                detail: '',
            ));
        }

        self::assertCount(2, $this->store->forResource('users', 2));
    }

    #[Test]
    public function handlesNullRecordId(): void
    {
        $entry = new ActionHistoryEntry(
            id: 'bulk-001',
            action: 'bulk.delete',
            resourceName: 'users',
            recordId: null,
            actor: 'admin',
            timestamp: 1700000000,
            success: true,
            detail: '5 records deleted',
        );

        $this->store->record($entry);

        $recent = $this->store->recent(10);
        self::assertCount(1, $recent);
        self::assertNull($recent[0]->recordId);
    }

    #[Test]
    public function recordsFailedAction(): void
    {
        $entry = new ActionHistoryEntry(
            id: 'fail-001',
            action: 'create',
            resourceName: 'users',
            recordId: null,
            actor: 'admin',
            timestamp: 1700000000,
            success: false,
            detail: 'Validation failed',
        );

        $this->store->record($entry);

        $recent = $this->store->recent(10);
        self::assertCount(1, $recent);
        self::assertFalse($recent[0]->success);
        self::assertSame('Validation failed', $recent[0]->detail);
    }
}
