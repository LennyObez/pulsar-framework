<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Storage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Internal\Storage\SchemaChangeLogEntry;

final class SchemaChangeLogEntryTest extends TestCase
{
    #[Test]
    public function construction(): void
    {
        $entry = new SchemaChangeLogEntry(
            id: 'scl_001',
            operation: 'create_table',
            table: 'customers',
            actor: 'admin',
            reason: 'New customer table',
            timestamp: 1700000000,
            statements: ['CREATE TABLE customers (id INT PRIMARY KEY)'],
            evidenceHash: 'abcdef1234567890',
            correlationId: 'corr_001',
            success: true,
        );

        self::assertSame('scl_001', $entry->id);
        self::assertSame('create_table', $entry->operation);
        self::assertSame('customers', $entry->table);
        self::assertSame('admin', $entry->actor);
        self::assertSame('New customer table', $entry->reason);
        self::assertSame(1700000000, $entry->timestamp);
        self::assertCount(1, $entry->statements);
        self::assertSame('abcdef1234567890', $entry->evidenceHash);
        self::assertSame('corr_001', $entry->correlationId);
        self::assertTrue($entry->success);
    }

    #[Test]
    public function construction_with_null_correlation(): void
    {
        $entry = new SchemaChangeLogEntry(
            id: 'scl_002',
            operation: 'drop_table',
            table: 'temp',
            actor: 'system',
            reason: 'Cleanup',
            timestamp: 1700001000,
            statements: ['DROP TABLE temp'],
            evidenceHash: 'deadbeef',
            correlationId: null,
            success: false,
        );

        self::assertNull($entry->correlationId);
        self::assertFalse($entry->success);
    }

    #[Test]
    public function multiple_statements(): void
    {
        $entry = new SchemaChangeLogEntry(
            id: 'scl_003',
            operation: 'alter_table',
            table: 'users',
            actor: 'admin',
            reason: 'Add columns',
            timestamp: 1700002000,
            statements: [
                'ALTER TABLE users ADD COLUMN email VARCHAR(255)',
                'CREATE INDEX idx_users_email ON users(email)',
            ],
            evidenceHash: 'cafebabe',
            correlationId: null,
            success: true,
        );

        self::assertCount(2, $entry->statements);
    }
}
