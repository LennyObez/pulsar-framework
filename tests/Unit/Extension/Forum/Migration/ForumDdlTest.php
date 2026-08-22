<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Migration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Extension\Forum\Migration\ForumDdl;

#[CoversClass(ForumDdl::class)]
final class ForumDdlTest extends TestCase
{
    #[Test]
    public function adaptPostgreSqlReplacesNowOnly(): void
    {
        $sql = 'created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()';
        $result = ForumDdl::adapt($sql, Driver::PostgreSQL);

        self::assertSame('created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP', $result);
    }

    #[Test]
    public function adaptPostgreSqlPreservesTimestamptz(): void
    {
        $sql = 'col TIMESTAMPTZ';
        $result = ForumDdl::adapt($sql, Driver::PostgreSQL);

        self::assertStringContainsString('TIMESTAMPTZ', $result);
    }

    #[Test]
    #[DataProvider('sqliteTypeProvider')]
    public function adaptSqliteReplacesTypes(string $input, string $expected): void
    {
        $result = ForumDdl::adapt($input, Driver::SQLite);

        self::assertStringContainsString($expected, $result);
    }

    /** @return iterable<string, array{string, string}> */
    public static function sqliteTypeProvider(): iterable
    {
        yield 'TIMESTAMPTZ to TEXT' => ['col TIMESTAMPTZ', 'col TEXT'];
        yield 'JSONB to TEXT' => ['col JSONB', 'col TEXT'];
        yield 'TSVECTOR to TEXT' => ['col TSVECTOR', 'col TEXT'];
        yield 'DOUBLE PRECISION to REAL' => ['col DOUBLE PRECISION', 'col REAL'];
    }

    #[Test]
    #[DataProvider('mysqlTypeProvider')]
    public function adaptMysqlReplacesTypes(string $input, string $expected): void
    {
        $result = ForumDdl::adapt($input, Driver::MySQL);

        self::assertStringContainsString($expected, $result);
    }

    /** @return iterable<string, array{string, string}> */
    public static function mysqlTypeProvider(): iterable
    {
        yield 'TIMESTAMPTZ to DATETIME' => ['col TIMESTAMPTZ', 'col DATETIME'];
        yield 'JSONB to JSON' => ['col JSONB', 'col JSON'];
        yield 'TSVECTOR to TEXT' => ['col TSVECTOR', 'col TEXT'];
        yield 'DOUBLE PRECISION to DOUBLE' => ['col DOUBLE PRECISION', 'col DOUBLE'];
    }

    #[Test]
    public function adaptReplacesNowForAllDrivers(): void
    {
        $sql = 'DEFAULT NOW()';

        foreach (Driver::cases() as $driver) {
            $result = ForumDdl::adapt($sql, $driver);
            self::assertStringContainsString('DEFAULT CURRENT_TIMESTAMP', $result);
            self::assertStringNotContainsString('NOW()', $result);
        }
    }

    #[Test]
    public function adaptPreservesUnrelatedSql(): void
    {
        $sql = 'CREATE TABLE IF NOT EXISTS test (id VARCHAR(36) NOT NULL PRIMARY KEY)';
        $result = ForumDdl::adapt($sql, Driver::SQLite);

        self::assertSame($sql, $result);
    }

    #[Test]
    public function adaptHandlesMultipleReplacementsInOneSql(): void
    {
        $sql = 'col1 TIMESTAMPTZ DEFAULT NOW(), col2 JSONB, col3 TSVECTOR, col4 DOUBLE PRECISION';

        $sqliteResult = ForumDdl::adapt($sql, Driver::SQLite);

        self::assertStringNotContainsString('TIMESTAMPTZ', $sqliteResult);
        self::assertStringNotContainsString('JSONB', $sqliteResult);
        self::assertStringNotContainsString('TSVECTOR', $sqliteResult);
        self::assertStringNotContainsString('DOUBLE PRECISION', $sqliteResult);
        self::assertStringNotContainsString('NOW()', $sqliteResult);
    }
}
