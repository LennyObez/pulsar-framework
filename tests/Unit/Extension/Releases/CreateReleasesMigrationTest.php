<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Releases;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;

/**
 * Tests for the releases 001_create_releases migration.
 *
 * Verifies that the migration file returns a valid MigrationInterface,
 * creates both releases and beta_signups tables for each driver, and
 * rolls back cleanly via down().
 */
final class CreateReleasesMigrationTest extends TestCase
{
    private const string MIGRATION_FILE = __DIR__ . '/../../../../extensions/releases/src/Migration/001_create_releases.php';

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

    // -- up() SQLite ----------------------------------------------------------

    #[Test]
    public function upSqliteExecutesFiveStatements(): void
    {
        $migration = $this->loadMigration();

        /** @var ConnectionInterface&MockObject $connection */
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);

        // releases: CREATE TABLE + 2 indexes = 3
        // beta_signups: CREATE TABLE + 1 unique index = 2
        // Total: 5 execute calls
        $executedSql = [];
        $connection->expects(self::exactly(5))
            ->method('execute')
            ->willReturnCallback(function (string $sql) use (&$executedSql): int {
                $executedSql[] = $sql;

                return 0;
            });

        $migration->up($connection);

        // Verify releases table
        self::assertStringContainsString('CREATE TABLE', $executedSql[0]);
        self::assertStringContainsString('releases', $executedSql[0]);

        // Verify releases indexes
        self::assertStringContainsString('idx_releases_platform_stable', $executedSql[1]);
        self::assertStringContainsString('idx_releases_platform_date', $executedSql[2]);

        // Verify beta_signups table
        self::assertStringContainsString('CREATE TABLE', $executedSql[3]);
        self::assertStringContainsString('beta_signups', $executedSql[3]);

        // Verify beta_signups unique index
        self::assertStringContainsString('idx_beta_signups_email', $executedSql[4]);
    }

    #[Test]
    public function upSqliteReleasesTableContainsAllColumns(): void
    {
        $migration = $this->loadMigration();

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);

        $statements = [];
        $connection->method('execute')
            ->willReturnCallback(function (string $sql) use (&$statements): int {
                $statements[] = $sql;

                return 0;
            });

        $migration->up($connection);

        $releasesTableSql = $statements[0];
        $expectedColumns = [
            'id', 'version', 'platform', 'release_date', 'release_notes',
            'minimum_os_version', 'download_url', 'is_beta', 'is_stable', 'created_at',
        ];

        foreach ($expectedColumns as $column) {
            self::assertStringContainsString($column, $releasesTableSql, "Column '$column' missing from releases table");
        }
    }

    #[Test]
    public function upSqliteBetaSignupsTableContainsAllColumns(): void
    {
        $migration = $this->loadMigration();

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);

        $statements = [];
        $connection->method('execute')
            ->willReturnCallback(function (string $sql) use (&$statements): int {
                $statements[] = $sql;

                return 0;
            });

        $migration->up($connection);

        $signupsTableSql = $statements[3];
        $expectedColumns = [
            'id', 'email', 'device_type', 'camera_brands',
            'signed_up_at', 'invited_at', 'invite_token_hash',
        ];

        foreach ($expectedColumns as $column) {
            self::assertStringContainsString($column, $signupsTableSql, "Column '$column' missing from beta_signups table");
        }
    }

    // -- up() MySQL -----------------------------------------------------------

    #[Test]
    public function upMysqlExecutesTwoStatements(): void
    {
        $migration = $this->loadMigration();

        /** @var ConnectionInterface&MockObject $connection */
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);

        $executedSql = [];
        $connection->expects(self::exactly(2))
            ->method('execute')
            ->willReturnCallback(function (string $sql) use (&$executedSql): int {
                $executedSql[] = $sql;

                return 0;
            });

        $migration->up($connection);

        // Releases table with InnoDB and inline indexes
        self::assertStringContainsString('CREATE TABLE', $executedSql[0]);
        self::assertStringContainsString('releases', $executedSql[0]);
        self::assertStringContainsString('ENGINE=InnoDB', $executedSql[0]);
        self::assertStringContainsString('utf8mb4', $executedSql[0]);
        self::assertStringContainsString('idx_releases_platform_stable', $executedSql[0]);
        self::assertStringContainsString('idx_releases_platform_date', $executedSql[0]);

        // Beta signups table with InnoDB and inline unique index
        self::assertStringContainsString('CREATE TABLE', $executedSql[1]);
        self::assertStringContainsString('beta_signups', $executedSql[1]);
        self::assertStringContainsString('ENGINE=InnoDB', $executedSql[1]);
        self::assertStringContainsString('idx_beta_signups_email', $executedSql[1]);
    }

    #[Test]
    public function upMysqlBetaSignupsUsesJsonType(): void
    {
        $migration = $this->loadMigration();

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);

        $statements = [];
        $connection->method('execute')
            ->willReturnCallback(function (string $sql) use (&$statements): int {
                $statements[] = $sql;

                return 0;
            });

        $migration->up($connection);

        // MySQL uses JSON type for camera_brands
        self::assertStringContainsString('JSON', $statements[1]);
    }

    // -- up() PostgreSQL ------------------------------------------------------

    #[Test]
    public function upPostgresqlExecutesFiveStatements(): void
    {
        $migration = $this->loadMigration();

        /** @var ConnectionInterface&MockObject $connection */
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::PostgreSQL);

        $executedSql = [];
        $connection->expects(self::exactly(5))
            ->method('execute')
            ->willReturnCallback(function (string $sql) use (&$executedSql): int {
                $executedSql[] = $sql;

                return 0;
            });

        $migration->up($connection);

        self::assertStringContainsString('CREATE TABLE', $executedSql[0]);
        self::assertStringContainsString('releases', $executedSql[0]);
        self::assertStringContainsString('TIMESTAMPTZ', $executedSql[0]);

        self::assertStringContainsString('idx_releases_platform_stable', $executedSql[1]);
        self::assertStringContainsString('idx_releases_platform_date', $executedSql[2]);

        self::assertStringContainsString('CREATE TABLE', $executedSql[3]);
        self::assertStringContainsString('beta_signups', $executedSql[3]);
        self::assertStringContainsString('JSONB', $executedSql[3]);

        self::assertStringContainsString('idx_beta_signups_email', $executedSql[4]);
    }

    #[Test]
    public function upPostgresqlUsesTimestamptzForReleases(): void
    {
        $migration = $this->loadMigration();

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::PostgreSQL);

        $firstSql = '';
        $connection->method('execute')
            ->willReturnCallback(function (string $sql) use (&$firstSql): int {
                if ($firstSql === '' && str_contains($sql, 'releases')) {
                    $firstSql = $sql;
                }

                return 0;
            });

        $migration->up($connection);

        self::assertStringContainsString('TIMESTAMPTZ', $firstSql);
    }

    #[Test]
    public function upPostgresqlUsesJsonbForCameraBrands(): void
    {
        $migration = $this->loadMigration();

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::PostgreSQL);

        $signupsSql = '';
        $connection->method('execute')
            ->willReturnCallback(function (string $sql) use (&$signupsSql): int {
                if (str_contains($sql, 'beta_signups') && str_contains($sql, 'CREATE TABLE')) {
                    $signupsSql = $sql;
                }

                return 0;
            });

        $migration->up($connection);

        self::assertStringContainsString('JSONB', $signupsSql);
    }

    // -- down() ---------------------------------------------------------------

    #[Test]
    public function downDropsBothTables(): void
    {
        $migration = $this->loadMigration();

        /** @var ConnectionInterface&MockObject $connection */
        $connection = $this->createMock(ConnectionInterface::class);

        $droppedSql = [];
        $connection->expects(self::exactly(2))
            ->method('execute')
            ->willReturnCallback(function (string $sql) use (&$droppedSql): int {
                $droppedSql[] = $sql;

                return 0;
            });

        $migration->down($connection);

        self::assertStringContainsString('DROP TABLE', $droppedSql[0]);
        self::assertStringContainsString('beta_signups', $droppedSql[0]);
        self::assertStringContainsString('IF EXISTS', $droppedSql[0]);

        self::assertStringContainsString('DROP TABLE', $droppedSql[1]);
        self::assertStringContainsString('releases', $droppedSql[1]);
        self::assertStringContainsString('IF EXISTS', $droppedSql[1]);
    }

    // -- driver coverage via data provider ------------------------------------

    /**
     * @return iterable<string, array{Driver, int}>
     */
    public static function driverStatementCountProvider(): iterable
    {
        yield 'SQLite' => [Driver::SQLite, 5];
        yield 'MySQL' => [Driver::MySQL, 2];
        yield 'PostgreSQL' => [Driver::PostgreSQL, 5];
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
