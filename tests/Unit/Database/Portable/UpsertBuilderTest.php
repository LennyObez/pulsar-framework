<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Portable;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\Portable\UpsertBuilder;

#[CoversClass(UpsertBuilder::class)]
final class UpsertBuilderTest extends TestCase
{
    #[Test]
    public function mysqlUsesBackticksAndOnDuplicateKey(): void
    {
        $sql = UpsertBuilder::compile(
            Driver::MySQL,
            'users',
            ['id', 'name', 'email'],
            ['id'],
            ['name', 'email'],
        );

        self::assertSame(
            'INSERT INTO `users` (`id`, `name`, `email`) VALUES (:id, :name, :email)'
            . ' ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `email` = VALUES(`email`)',
            $sql,
        );
    }

    #[Test]
    public function postgresqlUsesDoubleQuotesAndOnConflict(): void
    {
        $sql = UpsertBuilder::compile(
            Driver::PostgreSQL,
            'users',
            ['id', 'name', 'email'],
            ['id'],
            ['name', 'email'],
        );

        self::assertSame(
            'INSERT INTO "users" ("id", "name", "email") VALUES (:id, :name, :email)'
            . ' ON CONFLICT ("id") DO UPDATE SET "name" = EXCLUDED."name", "email" = EXCLUDED."email"',
            $sql,
        );
    }

    #[Test]
    public function sqliteUsesDoubleQuotesAndOnConflict(): void
    {
        $sql = UpsertBuilder::compile(
            Driver::SQLite,
            'users',
            ['id', 'name', 'email'],
            ['id'],
            ['name', 'email'],
        );

        self::assertSame(
            'INSERT INTO "users" ("id", "name", "email") VALUES (:id, :name, :email)'
            . ' ON CONFLICT ("id") DO UPDATE SET "name" = EXCLUDED."name", "email" = EXCLUDED."email"',
            $sql,
        );
    }

    #[Test]
    public function multipleConflictColumns(): void
    {
        $sql = UpsertBuilder::compile(
            Driver::PostgreSQL,
            'order_items',
            ['order_id', 'product_id', 'quantity'],
            ['order_id', 'product_id'],
            ['quantity'],
        );

        self::assertStringContainsString('ON CONFLICT ("order_id", "product_id")', $sql);
        self::assertStringContainsString('SET "quantity" = EXCLUDED."quantity"', $sql);
    }

    #[Test]
    public function mysqlExtraSetAppendsToUpdateClauses(): void
    {
        $sql = UpsertBuilder::compile(
            Driver::MySQL,
            'counters',
            ['id', 'value'],
            ['id'],
            ['value'],
            extraSet: 'version = version + 1',
        );

        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
        self::assertStringContainsString('`value` = VALUES(`value`)', $sql);
        self::assertStringContainsString('version = version + 1', $sql);
    }

    #[Test]
    public function postgresExtraSetAppendsToUpdateClauses(): void
    {
        $sql = UpsertBuilder::compile(
            Driver::PostgreSQL,
            'counters',
            ['id', 'value'],
            ['id'],
            ['value'],
            extraSet: 'version = counters.version + 1',
        );

        self::assertStringContainsString('DO UPDATE SET', $sql);
        self::assertStringContainsString('"value" = EXCLUDED."value"', $sql);
        self::assertStringContainsString('version = counters.version + 1', $sql);
    }

    #[Test]
    public function postgresExtraWhereAppendsWhereClause(): void
    {
        $sql = UpsertBuilder::compile(
            Driver::PostgreSQL,
            'settings',
            ['key', 'value', 'version'],
            ['key'],
            ['value', 'version'],
            extraWhere: 'settings.version < EXCLUDED.version',
        );

        self::assertStringEndsWith('WHERE settings.version < EXCLUDED.version', $sql);
    }

    #[Test]
    public function sqliteExtraWhereAppendsWhereClause(): void
    {
        $sql = UpsertBuilder::compile(
            Driver::SQLite,
            'settings',
            ['key', 'value'],
            ['key'],
            ['value'],
            extraWhere: 'settings.version < EXCLUDED.version',
        );

        self::assertStringEndsWith('WHERE settings.version < EXCLUDED.version', $sql);
    }

    #[Test]
    public function mysqlIgnoresExtraWhere(): void
    {
        // MySQL ON DUPLICATE KEY UPDATE does not support WHERE — extraWhere is silently ignored
        $sql = UpsertBuilder::compile(
            Driver::MySQL,
            'settings',
            ['key', 'value'],
            ['key'],
            ['value'],
            extraWhere: 'settings.version < VALUES(version)',
        );

        self::assertStringNotContainsString('WHERE', $sql);
    }

    #[Test]
    public function postgresExtraSetAndExtraWhereCombined(): void
    {
        $sql = UpsertBuilder::compile(
            Driver::PostgreSQL,
            'documents',
            ['id', 'body', 'version'],
            ['id'],
            ['body'],
            extraWhere: 'documents.version < EXCLUDED.version',
            extraSet: 'version = EXCLUDED.version',
        );

        self::assertStringContainsString('"body" = EXCLUDED."body", version = EXCLUDED.version', $sql);
        self::assertStringEndsWith('WHERE documents.version < EXCLUDED.version', $sql);
    }

    #[Test]
    public function singleColumnInsertAndUpdate(): void
    {
        $sql = UpsertBuilder::compile(
            Driver::MySQL,
            'locks',
            ['key'],
            ['key'],
            ['key'],
        );

        self::assertSame(
            'INSERT INTO `locks` (`key`) VALUES (:key) ON DUPLICATE KEY UPDATE `key` = VALUES(`key`)',
            $sql,
        );
    }

    #[Test]
    #[DataProvider('driverProvider')]
    public function insertPartContainsAllColumns(Driver $driver): void
    {
        $columns = ['id', 'tenant_id', 'name', 'email', 'created_at'];

        $sql = UpsertBuilder::compile(
            $driver,
            'contacts',
            $columns,
            ['id'],
            ['name', 'email'],
        );

        self::assertStringContainsString('VALUES (:id, :tenant_id, :name, :email, :created_at)', $sql);
    }

    #[Test]
    #[DataProvider('driverProvider')]
    public function tableNameIsQuoted(Driver $driver): void
    {
        $sql = UpsertBuilder::compile(
            $driver,
            'my_table',
            ['id'],
            ['id'],
            ['id'],
        );

        $q = $driver === Driver::MySQL ? '`' : '"';
        self::assertStringContainsString($q . 'my_table' . $q, $sql);
    }

    /**
     * @return iterable<string, array{Driver}>
     */
    public static function driverProvider(): iterable
    {
        yield 'MySQL' => [Driver::MySQL];
        yield 'PostgreSQL' => [Driver::PostgreSQL];
        yield 'SQLite' => [Driver::SQLite];
    }
}
