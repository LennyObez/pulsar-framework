<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Event\Payload;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Event\Payload\DatabaseQueryPayload;

final class DatabaseQueryPayloadTest extends TestCase
{
    #[Test]
    public function eventTypeReturnsDatabaseQuery(): void
    {
        $payload = $this->createPayload();

        self::assertSame(EventType::DatabaseQuery, $payload->eventType());
    }

    #[Test]
    public function schemaVersionReturnsV1(): void
    {
        self::assertSame(EventVersion::V1, $this->createPayload()->schemaVersion());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $payload = $this->createPayload();
        $array = $payload->toArray();

        self::assertSame('select * from users where id = ?', $array['sql']);
        self::assertSame('fp123', $array['sql_fingerprint']);
        self::assertSame('default', $array['connection_name']);
        self::assertSame(1.5, $array['duration_ms']);
        self::assertSame(1, $array['row_count']);
        self::assertSame('SELECT', $array['query_type']);
        self::assertNull($array['sql_raw']);
    }

    #[Test]
    public function toArrayIncludesRawSqlWhenProvided(): void
    {
        $payload = new DatabaseQueryPayload(
            sql: 'select * from users where id = ?',
            sqlFingerprint: 'fp123',
            connectionName: 'default',
            durationMs: 1.5,
            rowCount: 1,
            queryType: 'SELECT',
            sqlRaw: 'SELECT * FROM users WHERE id = 42',
        );

        self::assertSame('SELECT * FROM users WHERE id = 42', $payload->toArray()['sql_raw']);
    }

    private function createPayload(): DatabaseQueryPayload
    {
        return new DatabaseQueryPayload(
            sql: 'select * from users where id = ?',
            sqlFingerprint: 'fp123',
            connectionName: 'default',
            durationMs: 1.5,
            rowCount: 1,
            queryType: 'SELECT',
        );
    }
}
