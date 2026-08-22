<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Migration;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationRepository;
use Pulsar\Database\Migration\MigrationRunner;
use Pulsar\Database\PdoConnection;

use function str_repeat;
use function sys_get_temp_dir;

/**
 * Guards that MigrationRunner rejects a migrations table name that is not a
 * valid SQL identifier. The name is interpolated into DDL/DML, MySQL
 * GET_LOCK/RELEASE_LOCK string literals, and the SQLite flock path, so an
 * invalid name is an identifier-injection / path-traversal risk that must be
 * rejected at construction before any of those are composed.
 */
#[CoversClass(MigrationRunner::class)]
final class MigrationRunnerTableNameTest extends TestCase
{
    private function makeConnection(): PdoConnection
    {
        return new PdoConnection(
            connectionName: 'test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );
    }

    private function makeRepository(): MigrationRepository
    {
        // These constructor-validation tests never read migration files; the
        // system temp dir is a stable, always-present path.
        return new MigrationRepository(sys_get_temp_dir());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTableNameProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'leading digit' => ['1_migrations'];
        yield 'semicolon SQL injection' => ['migrations;DROP TABLE users--'];
        yield 'single quote (GET_LOCK literal injection)' => ["a'b"];
        yield 'dot (cross-schema identifier)' => ['schema.migrations'];
        yield 'hyphen' => ['my-migrations'];
        yield 'path traversal' => ['../etc/passwd'];
        yield 'exceeds 64 characters' => [str_repeat('a', 65)];
        yield 'reserved word' => ['select'];
    }

    #[Test]
    #[DataProvider('invalidTableNameProvider')]
    public function constructorRejectsInvalidTableName(string $tableName): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/invalid migrations table name/i');

        new MigrationRunner($this->makeConnection(), $this->makeRepository(), $tableName);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validTableNameProvider(): iterable
    {
        yield 'plain' => ['migrations'];
        yield 'default prefix' => ['pulsar_migrations'];
        yield 'tenant prefix' => ['tenant_42_migrations'];
        yield 'underscore start' => ['_schema_history'];
        yield 'max length (64)' => [str_repeat('a', 64)];
    }

    #[Test]
    #[DataProvider('validTableNameProvider')]
    public function constructorAcceptsValidTableName(string $tableName): void
    {
        $runner = new MigrationRunner($this->makeConnection(), $this->makeRepository(), $tableName);

        self::assertInstanceOf(MigrationRunner::class, $runner);
    }
}
