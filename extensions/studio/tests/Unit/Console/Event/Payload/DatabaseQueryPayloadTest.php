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

    #[Test]
    public function toArrayIncludesCallSiteFieldsWhenProvided(): void
    {
        $payload = new DatabaseQueryPayload(
            sql: 'select * from users where id = ?',
            sqlFingerprint: 'fp123',
            connectionName: 'default',
            durationMs: 2.5,
            rowCount: 1,
            queryType: 'SELECT',
            callSiteFile: '/app/src/Repository/UserRepository.php',
            callSiteLine: 42,
            callSiteClass: 'App\\Repository\\UserRepository',
            callSiteMethod: 'findById',
        );

        $arr = $payload->toArray();

        self::assertSame('/app/src/Repository/UserRepository.php', $arr['call_site_file']);
        self::assertSame(42, $arr['call_site_line']);
        self::assertSame('App\\Repository\\UserRepository', $arr['call_site_class']);
        self::assertSame('findById', $arr['call_site_method']);
    }

    #[Test]
    public function toArrayDefaultsCallSiteToNull(): void
    {
        $payload = $this->createPayload();
        $arr = $payload->toArray();

        self::assertNull($arr['call_site_file']);
        self::assertNull($arr['call_site_line']);
        self::assertNull($arr['call_site_class']);
        self::assertNull($arr['call_site_method']);
    }

    #[Test]
    public function callSitePropertiesAreReadable(): void
    {
        $payload = new DatabaseQueryPayload(
            sql: 'INSERT INTO logs VALUES (?)',
            sqlFingerprint: 'ghi789',
            connectionName: 'default',
            durationMs: 1.0,
            rowCount: 1,
            queryType: 'INSERT',
            callSiteFile: '/app/src/Service/LogService.php',
            callSiteLine: 15,
            callSiteClass: 'App\\Service\\LogService',
            callSiteMethod: 'append',
        );

        self::assertSame('/app/src/Service/LogService.php', $payload->callSiteFile);
        self::assertSame(15, $payload->callSiteLine);
        self::assertSame('App\\Service\\LogService', $payload->callSiteClass);
        self::assertSame('append', $payload->callSiteMethod);
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
