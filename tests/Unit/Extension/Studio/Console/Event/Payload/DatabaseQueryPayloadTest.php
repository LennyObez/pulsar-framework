<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Console\Event\Payload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Event\Payload\DatabaseQueryPayload;

#[CoversClass(DatabaseQueryPayload::class)]
final class DatabaseQueryPayloadTest extends TestCase
{
    #[Test]
    public function eventTypeReturnsDatabaseQuery(): void
    {
        $payload = new DatabaseQueryPayload(
            sql: 'select * from users where id = ?',
            sqlFingerprint: 'abc123',
            connectionName: 'default',
            durationMs: 5.2,
            rowCount: 1,
            queryType: 'SELECT',
        );

        self::assertSame(EventType::DatabaseQuery, $payload->eventType());
    }

    #[Test]
    public function schemaVersionReturnsV1(): void
    {
        $payload = new DatabaseQueryPayload(
            sql: 'select 1',
            sqlFingerprint: 'x',
            connectionName: 'default',
            durationMs: 0.1,
            rowCount: null,
            queryType: 'SELECT',
        );

        self::assertSame(EventVersion::V1, $payload->schemaVersion());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $payload = new DatabaseQueryPayload(
            sql: 'select * from users where id = ?',
            sqlFingerprint: 'fp123',
            connectionName: 'mysql',
            durationMs: 12.5,
            rowCount: 3,
            queryType: 'SELECT',
            sqlRaw: 'SELECT * FROM users WHERE id = 42',
        );

        $data = $payload->toArray();

        self::assertSame('select * from users where id = ?', $data['sql']);
        self::assertSame('fp123', $data['sql_fingerprint']);
        self::assertSame('mysql', $data['connection_name']);
        self::assertSame(12.5, $data['duration_ms']);
        self::assertSame(3, $data['row_count']);
        self::assertSame('SELECT', $data['query_type']);
        self::assertSame('SELECT * FROM users WHERE id = 42', $data['sql_raw']);
    }

    #[Test]
    public function toArrayIncludesNullRawSqlByDefault(): void
    {
        $payload = new DatabaseQueryPayload(
            sql: 'select 1',
            sqlFingerprint: 'x',
            connectionName: 'default',
            durationMs: 0.1,
            rowCount: null,
            queryType: 'SELECT',
        );

        $data = $payload->toArray();

        self::assertNull($data['sql_raw']);
    }
}
