<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Event\Payload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Event\Payload\DatabaseQueryPayload;

#[CoversClass(DatabaseQueryPayload::class)]
final class DatabaseQueryPayloadTest extends TestCase
{
    #[Test]
    public function implementsConsoleEventInterface(): void
    {
        $payload = new DatabaseQueryPayload(
            sql: 'SELECT * FROM users WHERE id = ?',
            sqlFingerprint: 'abc123',
            connectionName: 'default',
            durationMs: 10.0,
            rowCount: null,
            queryType: 'SELECT',
        );

        self::assertInstanceOf(ConsoleEvent::class, $payload);
    }

    #[Test]
    public function eventTypeReturnsDatabaseQuery(): void
    {
        $payload = new DatabaseQueryPayload(
            sql: 'SELECT * FROM users',
            sqlFingerprint: 'def456',
            connectionName: 'default',
            durationMs: 5.0,
            rowCount: 10,
            queryType: 'SELECT',
        );

        self::assertSame(EventType::DatabaseQuery, $payload->eventType());
    }

    #[Test]
    public function schemaVersionReturnsV1(): void
    {
        $payload = new DatabaseQueryPayload(
            sql: 'SELECT 1',
            sqlFingerprint: 'ghi789',
            connectionName: 'default',
            durationMs: 1.0,
            rowCount: 1,
            queryType: 'SELECT',
        );

        self::assertSame(EventVersion::V1, $payload->schemaVersion());
    }

    #[Test]
    public function constructorSetsAllRequiredProperties(): void
    {
        $payload = new DatabaseQueryPayload(
            sql: 'INSERT INTO users (name, email) VALUES (?, ?)',
            sqlFingerprint: 'jkl012',
            connectionName: 'primary',
            durationMs: 25.5,
            rowCount: 1,
            queryType: 'INSERT',
        );

        self::assertSame('INSERT INTO users (name, email) VALUES (?, ?)', $payload->sql);
        self::assertSame('jkl012', $payload->sqlFingerprint);
        self::assertSame('primary', $payload->connectionName);
        self::assertSame(25.5, $payload->durationMs);
        self::assertSame(1, $payload->rowCount);
        self::assertSame('INSERT', $payload->queryType);
        self::assertNull($payload->sqlRaw);
    }

    #[Test]
    public function constructorSetsOptionalSqlRaw(): void
    {
        $payload = new DatabaseQueryPayload(
            sql: 'SELECT * FROM users WHERE id = ?',
            sqlFingerprint: 'mno345',
            connectionName: 'default',
            durationMs: 3.0,
            rowCount: 1,
            queryType: 'SELECT',
            sqlRaw: 'SELECT * FROM users WHERE id = 42',
        );

        self::assertSame('SELECT * FROM users WHERE id = 42', $payload->sqlRaw);
    }

    #[Test]
    public function toArrayContainsAllExpectedKeys(): void
    {
        $payload = new DatabaseQueryPayload(
            sql: 'SELECT 1',
            sqlFingerprint: 'pqr678',
            connectionName: 'default',
            durationMs: 1.0,
            rowCount: 1,
            queryType: 'SELECT',
        );

        $array = $payload->toArray();

        self::assertArrayHasKey('sql', $array);
        self::assertArrayHasKey('sql_fingerprint', $array);
        self::assertArrayHasKey('connection_name', $array);
        self::assertArrayHasKey('duration_ms', $array);
        self::assertArrayHasKey('row_count', $array);
        self::assertArrayHasKey('query_type', $array);
        self::assertArrayHasKey('sql_raw', $array);
    }

    #[Test]
    public function toArrayReturnsCorrectValues(): void
    {
        $payload = new DatabaseQueryPayload(
            sql: 'UPDATE users SET status = ? WHERE id = ?',
            sqlFingerprint: 'stu901',
            connectionName: 'write',
            durationMs: 15.75,
            rowCount: 5,
            queryType: 'UPDATE',
            sqlRaw: "UPDATE users SET status = 'active' WHERE id = 123",
        );

        $array = $payload->toArray();

        self::assertSame('UPDATE users SET status = ? WHERE id = ?', $array['sql']);
        self::assertSame('stu901', $array['sql_fingerprint']);
        self::assertSame('write', $array['connection_name']);
        self::assertSame(15.75, $array['duration_ms']);
        self::assertSame(5, $array['row_count']);
        self::assertSame('UPDATE', $array['query_type']);
        self::assertSame("UPDATE users SET status = 'active' WHERE id = 123", $array['sql_raw']);
    }

    #[Test]
    public function toArrayHandlesNullRowCount(): void
    {
        $payload = new DatabaseQueryPayload(
            sql: 'DELETE FROM sessions WHERE expired_at < ?',
            sqlFingerprint: 'vwx234',
            connectionName: 'default',
            durationMs: 50.0,
            rowCount: null,
            queryType: 'DELETE',
        );

        $array = $payload->toArray();

        self::assertNull($array['row_count']);
    }

    #[Test]
    public function toArrayHandlesNullSqlRaw(): void
    {
        $payload = new DatabaseQueryPayload(
            sql: 'SELECT * FROM config',
            sqlFingerprint: 'yza567',
            connectionName: 'default',
            durationMs: 2.0,
            rowCount: 50,
            queryType: 'SELECT',
        );

        $array = $payload->toArray();

        self::assertNull($array['sql_raw']);
    }

    #[Test]
    public function handlesVariousQueryTypes(): void
    {
        $queryTypes = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'ALTER', 'DROP', 'TRUNCATE'];

        foreach ($queryTypes as $queryType) {
            $payload = new DatabaseQueryPayload(
                sql: 'test query',
                sqlFingerprint: 'test',
                connectionName: 'default',
                durationMs: 1.0,
                rowCount: null,
                queryType: $queryType,
            );

            self::assertSame($queryType, $payload->queryType);
            self::assertSame($queryType, $payload->toArray()['query_type']);
        }
    }

    #[Test]
    public function handlesZeroDuration(): void
    {
        $payload = new DatabaseQueryPayload(
            sql: 'SELECT 1',
            sqlFingerprint: 'bcd890',
            connectionName: 'default',
            durationMs: 0.0,
            rowCount: 1,
            queryType: 'SELECT',
        );

        self::assertSame(0.0, $payload->durationMs);
        self::assertSame(0.0, $payload->toArray()['duration_ms']);
    }

    #[Test]
    public function handlesZeroRowCount(): void
    {
        $payload = new DatabaseQueryPayload(
            sql: 'SELECT * FROM empty_table',
            sqlFingerprint: 'efg123',
            connectionName: 'default',
            durationMs: 1.0,
            rowCount: 0,
            queryType: 'SELECT',
        );

        self::assertSame(0, $payload->rowCount);
        self::assertSame(0, $payload->toArray()['row_count']);
    }

    #[Test]
    public function handlesDifferentConnectionNames(): void
    {
        $connectionNames = ['default', 'primary', 'replica', 'analytics', 'tenant_1'];

        foreach ($connectionNames as $connectionName) {
            $payload = new DatabaseQueryPayload(
                sql: 'SELECT 1',
                sqlFingerprint: 'hij456',
                connectionName: $connectionName,
                durationMs: 1.0,
                rowCount: 1,
                queryType: 'SELECT',
            );

            self::assertSame($connectionName, $payload->connectionName);
            self::assertSame($connectionName, $payload->toArray()['connection_name']);
        }
    }
}
