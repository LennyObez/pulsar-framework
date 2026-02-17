<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Devices;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;

/**
 * Tests for the devices 001_create_user_devices migration.
 *
 * Verifies that the migration file returns a valid MigrationInterface,
 * executes the correct DDL for each supported driver, and rolls back cleanly.
 */
final class CreateUserDevicesMigrationTest extends TestCase
{
    private const string MIGRATION_FILE = __DIR__ . '/../../../../extensions/devices/src/Migration/001_create_user_devices.php';

    private function loadMigration(): MigrationInterface
    {
        /** @var MigrationInterface $migration */
        $migration = require self::MIGRATION_FILE;

        return $migration;
    }

    #[Test]
    public function migrationFileReturnsMigrationInterface(): void
    {
        $migration = $this->loadMigration();

        self::assertInstanceOf(MigrationInterface::class, $migration);
    }

    // -- up() per driver -------------------------------------------------------

    #[Test]
    public function upSqliteExecutesThreeStatements(): void
    {
        $migration = $this->loadMigration();

        /** @var ConnectionInterface&MockObject $connection */
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);

        // CREATE TABLE + CREATE INDEX (user_id) + CREATE UNIQUE INDEX (token_hash)
        $executedSql = [];
        $connection->expects(self::exactly(3))
            ->method('execute')
            ->willReturnCallback(function (string $sql) use (&$executedSql): int {
                $executedSql[] = $sql;

                return 0;
            });

        $migration->up($connection);

        self::assertStringContainsString('CREATE TABLE', $executedSql[0]);
        self::assertStringContainsString('user_devices', $executedSql[0]);
        self::assertStringContainsString('idx_user_devices_user_id', $executedSql[1]);
        self::assertStringContainsString('idx_user_devices_token_hash', $executedSql[2]);
    }

    #[Test]
    public function upSqliteCreateTableContainsAllColumns(): void
    {
        $migration = $this->loadMigration();

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);

        $tableSql = '';
        $connection->method('execute')
            ->willReturnCallback(function (string $sql) use (&$tableSql): int {
                if (str_contains($sql, 'CREATE TABLE')) {
                    $tableSql = $sql;
                }

                return 0;
            });

        $migration->up($connection);

        $expectedColumns = ['id', 'user_id', 'device_name', 'platform', 'app_version', 'api_token_hash', 'last_seen_at', 'created_at'];

        foreach ($expectedColumns as $column) {
            self::assertStringContainsString($column, $tableSql, "Column '$column' missing from CREATE TABLE");
        }
    }

    #[Test]
    public function upSqliteCreateTableHasForeignKey(): void
    {
        $migration = $this->loadMigration();

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);

        $tableSql = '';
        $connection->method('execute')
            ->willReturnCallback(function (string $sql) use (&$tableSql): int {
                if (str_contains($sql, 'CREATE TABLE')) {
                    $tableSql = $sql;
                }

                return 0;
            });

        $migration->up($connection);

        self::assertStringContainsString('FOREIGN KEY', $tableSql);
        self::assertStringContainsString('auth_users', $tableSql);
        self::assertStringContainsString('ON DELETE CASCADE', $tableSql);
    }

    #[Test]
    public function upMysqlExecutesSingleStatement(): void
    {
        $migration = $this->loadMigration();

        /** @var ConnectionInterface&MockObject $connection */
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);

        $executedSql = '';
        $connection->expects(self::once())
            ->method('execute')
            ->willReturnCallback(function (string $sql) use (&$executedSql): int {
                $executedSql = $sql;

                return 0;
            });

        $migration->up($connection);

        self::assertStringContainsString('CREATE TABLE', $executedSql);
        self::assertStringContainsString('user_devices', $executedSql);
        self::assertStringContainsString('ENGINE=InnoDB', $executedSql);
        self::assertStringContainsString('utf8mb4', $executedSql);
    }

    #[Test]
    public function upMysqlIncludesInlineIndexes(): void
    {
        $migration = $this->loadMigration();

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);

        $executedSql = '';
        $connection->method('execute')
            ->willReturnCallback(function (string $sql) use (&$executedSql): int {
                $executedSql = $sql;

                return 0;
            });

        $migration->up($connection);

        self::assertStringContainsString('idx_user_devices_user_id', $executedSql);
        self::assertStringContainsString('idx_user_devices_token_hash', $executedSql);
        self::assertStringContainsString('FOREIGN KEY', $executedSql);
    }

    #[Test]
    public function upPostgresqlExecutesThreeStatements(): void
    {
        $migration = $this->loadMigration();

        /** @var ConnectionInterface&MockObject $connection */
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::PostgreSQL);

        $executedSql = [];
        $connection->expects(self::exactly(3))
            ->method('execute')
            ->willReturnCallback(function (string $sql) use (&$executedSql): int {
                $executedSql[] = $sql;

                return 0;
            });

        $migration->up($connection);

        self::assertStringContainsString('CREATE TABLE', $executedSql[0]);
        self::assertStringContainsString('TIMESTAMPTZ', $executedSql[0]);
        self::assertStringContainsString('idx_user_devices_user_id', $executedSql[1]);
        self::assertStringContainsString('idx_user_devices_token_hash', $executedSql[2]);
    }

    #[Test]
    public function upPostgresqlUsesTimestamptz(): void
    {
        $migration = $this->loadMigration();

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::PostgreSQL);

        $tableSql = '';
        $connection->method('execute')
            ->willReturnCallback(function (string $sql) use (&$tableSql): int {
                if (str_contains($sql, 'CREATE TABLE')) {
                    $tableSql = $sql;
                }

                return 0;
            });

        $migration->up($connection);

        self::assertStringContainsString('TIMESTAMPTZ', $tableSql);
    }

    // -- down() ---------------------------------------------------------------

    #[Test]
    public function downDropsUserDevicesTable(): void
    {
        $migration = $this->loadMigration();

        /** @var ConnectionInterface&MockObject $connection */
        $connection = $this->createMock(ConnectionInterface::class);

        $droppedSql = '';
        $connection->expects(self::once())
            ->method('execute')
            ->willReturnCallback(function (string $sql) use (&$droppedSql): int {
                $droppedSql = $sql;

                return 0;
            });

        $migration->down($connection);

        self::assertStringContainsString('DROP TABLE', $droppedSql);
        self::assertStringContainsString('user_devices', $droppedSql);
        self::assertStringContainsString('IF EXISTS', $droppedSql);
    }

    // -- driver coverage via data provider ------------------------------------

    /**
     * @return iterable<string, array{Driver, int}>
     */
    public static function driverStatementCountProvider(): iterable
    {
        yield 'SQLite' => [Driver::SQLite, 3];
        yield 'MySQL' => [Driver::MySQL, 1];
        yield 'PostgreSQL' => [Driver::PostgreSQL, 3];
    }

    #[Test]
    #[DataProvider('driverStatementCountProvider')]
    public function upExecutesExpectedStatementCountPerDriver(Driver $driver, int $expectedCount): void
    {
        $migration = $this->loadMigration();

        /** @var ConnectionInterface&MockObject $connection */
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn($driver);

        $connection->expects(self::exactly($expectedCount))
            ->method('execute');

        $migration->up($connection);
    }
}
