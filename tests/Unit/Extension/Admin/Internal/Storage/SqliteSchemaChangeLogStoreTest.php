<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Internal\Storage;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Internal\Storage\SchemaChangeLogEntry;
use Pulsar\Extension\Admin\Internal\Storage\SqliteSchemaChangeLogStore;

#[CoversClass(SqliteSchemaChangeLogStore::class)]
final class SqliteSchemaChangeLogStoreTest extends TestCase
{
    private SqliteSchemaChangeLogStore $store;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->store = new SqliteSchemaChangeLogStore($pdo);
    }

    #[Test]
    public function recordAndRetrieve(): void
    {
        $entry = new SchemaChangeLogEntry(
            id: 'test-001',
            operation: 'create_table',
            table: 'users',
            actor: 'admin@example.com',
            reason: 'Initial setup',
            timestamp: 1700000000,
            statements: ['CREATE TABLE "users" ("id" INTEGER PRIMARY KEY)'],
            evidenceHash: 'abc123',
            correlationId: 'corr-001',
            success: true,
        );

        $this->store->record($entry);

        $recent = $this->store->recent(10);
        self::assertCount(1, $recent);
        self::assertSame('test-001', $recent[0]->id);
        self::assertSame('create_table', $recent[0]->operation);
        self::assertSame('users', $recent[0]->table);
        self::assertSame('admin@example.com', $recent[0]->actor);
        self::assertSame('Initial setup', $recent[0]->reason);
        self::assertSame(['CREATE TABLE "users" ("id" INTEGER PRIMARY KEY)'], $recent[0]->statements);
        self::assertSame('abc123', $recent[0]->evidenceHash);
        self::assertSame('corr-001', $recent[0]->correlationId);
        self::assertTrue($recent[0]->success);
    }

    #[Test]
    public function forTableFilters(): void
    {
        $this->store->record(new SchemaChangeLogEntry('a', 'create_table', 'users', 'admin', 'r1', 1, ['SQL1'], 'h1', null, true));
        $this->store->record(new SchemaChangeLogEntry('b', 'create_table', 'orders', 'admin', 'r2', 2, ['SQL2'], 'h2', null, true));

        $result = $this->store->forTable('users');
        self::assertCount(1, $result);
        self::assertSame('users', $result[0]->table);
    }

    #[Test]
    public function exportSqlBundleFormat(): void
    {
        $this->store->record(new SchemaChangeLogEntry(
            'x',
            'create_table',
            'items',
            'admin',
            'setup',
            1700000000,
            ['CREATE TABLE "items" ("id" INTEGER PRIMARY KEY)'],
            'hash1',
            null,
            true,
        ));

        $bundle = $this->store->exportSqlBundle();

        self::assertStringContainsString('-- Schema Change Log Export', $bundle);
        self::assertStringContainsString('-- Evidence Hash: sha256:', $bundle);
        self::assertStringContainsString('create_table "items"', $bundle);
        self::assertStringContainsString('-- Reason: setup', $bundle);
        self::assertStringContainsString('CREATE TABLE "items"', $bundle);
    }

    #[Test]
    public function recentRespectsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->store->record(new SchemaChangeLogEntry(
                "id-{$i}",
                'add_column',
                'users',
                'admin',
                'reason',
                $i,
                ["SQL{$i}"],
                "h{$i}",
                null,
                true,
            ));
        }

        self::assertCount(3, $this->store->recent(3));
        self::assertCount(5, $this->store->recent(10));
    }
}
