<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Storage;

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

    #[Test]
    public function recentOrdersByTimestampDescending(): void
    {
        $this->store->record(new SchemaChangeLogEntry('a', 'create_table', 'users', 'admin', 'r1', 100, ['SQL1'], 'h1', null, true));
        $this->store->record(new SchemaChangeLogEntry('b', 'add_column', 'users', 'admin', 'r2', 300, ['SQL2'], 'h2', null, true));
        $this->store->record(new SchemaChangeLogEntry('c', 'drop_column', 'users', 'admin', 'r3', 200, ['SQL3'], 'h3', null, true));

        $recent = $this->store->recent(10);
        self::assertSame('b', $recent[0]->id);
        self::assertSame('c', $recent[1]->id);
        self::assertSame('a', $recent[2]->id);
    }

    #[Test]
    public function handlesNullCorrelationId(): void
    {
        $this->store->record(new SchemaChangeLogEntry(
            'null-corr',
            'create_table',
            'products',
            'admin',
            'reason',
            1700000000,
            ['CREATE TABLE products (id INT)'],
            'hash1',
            null,
            true,
        ));

        $recent = $this->store->recent(10);
        self::assertCount(1, $recent);
        self::assertNull($recent[0]->correlationId);
    }

    #[Test]
    public function recordsFailedOperation(): void
    {
        $this->store->record(new SchemaChangeLogEntry(
            'fail-001',
            'drop_table',
            'users',
            'admin',
            'cleanup',
            1700000000,
            ['DROP TABLE users'],
            'hash1',
            null,
            false,
        ));

        $recent = $this->store->recent(10);
        self::assertCount(1, $recent);
        self::assertFalse($recent[0]->success);
    }

    #[Test]
    public function exportSqlBundleMarksFailed(): void
    {
        $this->store->record(new SchemaChangeLogEntry(
            'f1',
            'drop_table',
            'items',
            'admin',
            'oops',
            1700000000,
            ['DROP TABLE "items"'],
            'hash1',
            null,
            false,
        ));

        $bundle = $this->store->exportSqlBundle();
        self::assertStringContainsString('[FAILED]', $bundle);
    }
}
