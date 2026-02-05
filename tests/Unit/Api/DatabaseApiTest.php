<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\ConnectionManagerInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Exception\DatabaseException;
use Pulsar\Database\FetchMode;
use Pulsar\Database\Migration\MigrationDirection;
use Pulsar\Database\Migration\MigrationFile;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Migration\MigrationRecord;
use Pulsar\Database\Result;
use Pulsar\Database\Row;

#[CoversClass(Api::class)]
final class DatabaseApiTest extends TestCase
{
    use ApiAssertionsTrait;

    #[Test]
    public function connectionInterfaceIsPublicApi(): void
    {
        self::assertHasApiAttribute(ConnectionInterface::class);
    }

    #[Test]
    public function connectionManagerInterfaceIsPublicApi(): void
    {
        self::assertHasApiAttribute(ConnectionManagerInterface::class);
    }

    #[Test]
    public function migrationInterfaceIsPublicApi(): void
    {
        self::assertHasApiAttribute(MigrationInterface::class);
    }

    #[Test]
    public function driverEnumIsPublicApi(): void
    {
        self::assertHasApiAttribute(Driver::class);
        self::assertEnumCases(Driver::class, ['MySQL', 'PostgreSQL', 'SQLite']);
    }

    #[Test]
    public function fetchModeEnumIsPublicApi(): void
    {
        self::assertHasApiAttribute(FetchMode::class);
    }

    #[Test]
    public function migrationDirectionEnumIsPublicApi(): void
    {
        self::assertHasApiAttribute(MigrationDirection::class);
        self::assertEnumCases(MigrationDirection::class, ['Up', 'Down']);
    }

    #[Test]
    public function resultIsPublicApi(): void
    {
        self::assertHasApiAttribute(Result::class);
        self::assertClassIsReadonly(Result::class);
    }

    #[Test]
    public function rowIsPublicApi(): void
    {
        self::assertHasApiAttribute(Row::class);
        self::assertClassIsReadonly(Row::class);
    }

    #[Test]
    public function migrationValueObjectsArePublicApi(): void
    {
        self::assertHasApiAttribute(MigrationFile::class);
        self::assertHasApiAttribute(MigrationRecord::class);
        self::assertClassIsReadonly(MigrationFile::class);
        self::assertClassIsReadonly(MigrationRecord::class);
    }

    #[Test]
    public function databaseExceptionIsPublicApi(): void
    {
        self::assertHasApiAttribute(DatabaseException::class);
    }

    #[Test]
    public function databaseExceptionHasStaticFactories(): void
    {
        self::assertStaticFactoryExists(DatabaseException::class, 'connectionFailed');
    }
}
