<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Exception\DatabaseException;
use RuntimeException;

#[CoversClass(DatabaseException::class)]
final class DatabaseExceptionTest extends TestCase
{
    #[Test]
    public function extendsRuntimeException(): void
    {
        $exception = DatabaseException::emptyResult();

        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    #[Test]
    public function connectionFailedContainsName(): void
    {
        $exception = DatabaseException::connectionFailed('primary');

        self::assertSame('Failed to connect to database "primary"', $exception->getMessage());
        self::assertNull($exception->getPrevious());
    }

    #[Test]
    public function connectionFailedChainsPreviousException(): void
    {
        $previous = new RuntimeException('Connection refused');
        $exception = DatabaseException::connectionFailed('primary', $previous);

        self::assertSame($previous, $exception->getPrevious());
        self::assertStringContainsString('primary', $exception->getMessage());
    }

    #[Test]
    public function connectionNotConfiguredContainsName(): void
    {
        $exception = DatabaseException::connectionNotConfigured('analytics');

        self::assertSame('Database connection "analytics" is not configured', $exception->getMessage());
    }

    #[Test]
    public function queryFailedContainsSql(): void
    {
        $exception = DatabaseException::queryFailed('SELECT * FROM users');

        self::assertSame('Query failed: SELECT * FROM users', $exception->getMessage());
        self::assertNull($exception->getPrevious());
    }

    #[Test]
    public function queryFailedChainsPreviousException(): void
    {
        $previous = new RuntimeException('Deadlock detected');
        $exception = DatabaseException::queryFailed('INSERT INTO orders', $previous);

        self::assertSame($previous, $exception->getPrevious());
    }

    #[Test]
    public function prepareErrorContainsSql(): void
    {
        $exception = DatabaseException::prepareError('SELECT ? FROM ??');

        self::assertSame('Failed to prepare statement: SELECT ? FROM ??', $exception->getMessage());
        self::assertNull($exception->getPrevious());
    }

    #[Test]
    public function prepareErrorChainsPreviousException(): void
    {
        $previous = new RuntimeException('Syntax error');
        $exception = DatabaseException::prepareError('INVALID SQL', $previous);

        self::assertSame($previous, $exception->getPrevious());
    }

    #[Test]
    public function emptyResultHasFixedMessage(): void
    {
        $exception = DatabaseException::emptyResult();

        self::assertSame('Query returned an empty result set', $exception->getMessage());
    }

    #[Test]
    public function columnNotFoundContainsColumnName(): void
    {
        $exception = DatabaseException::columnNotFound('email');

        self::assertSame('Column "email" not found in row', $exception->getMessage());
    }

    #[Test]
    public function typeCastFailedContainsColumnAndType(): void
    {
        $exception = DatabaseException::typeCastFailed('age', 'int');

        self::assertSame('Cannot cast column "age" to int', $exception->getMessage());
    }

    #[Test]
    public function transactionAlreadyFinishedHasFixedMessage(): void
    {
        $exception = DatabaseException::transactionAlreadyFinished();

        self::assertSame('Transaction has already been committed or rolled back', $exception->getMessage());
    }

    #[Test]
    public function migrationFailedContainsVersionAndDirection(): void
    {
        $exception = DatabaseException::migrationFailed('20240101_000001', 'up');

        self::assertSame('Migration 20240101_000001 (up) failed', $exception->getMessage());
        self::assertNull($exception->getPrevious());
    }

    #[Test]
    public function migrationFailedChainsPreviousException(): void
    {
        $previous = new RuntimeException('Table already exists');
        $exception = DatabaseException::migrationFailed('20240101_000001', 'up', $previous);

        self::assertSame($previous, $exception->getPrevious());
    }

    #[Test]
    public function migrationTableErrorContainsReason(): void
    {
        $exception = DatabaseException::migrationTableError('permission denied');

        self::assertSame('Migration table error: permission denied', $exception->getMessage());
        self::assertNull($exception->getPrevious());
    }

    #[Test]
    public function migrationTableErrorChainsPreviousException(): void
    {
        $previous = new RuntimeException('Access denied');
        $exception = DatabaseException::migrationTableError('cannot create table', $previous);

        self::assertSame($previous, $exception->getPrevious());
    }

    #[Test]
    public function migrationNotFoundContainsVersion(): void
    {
        $exception = DatabaseException::migrationNotFound('20240601_123456');

        self::assertSame('Migration "20240601_123456" not found', $exception->getMessage());
    }

    #[Test]
    public function duplicateMigrationVersionContainsVersion(): void
    {
        $exception = DatabaseException::duplicateMigrationVersion('20240101_000001');

        self::assertSame('Duplicate migration version: 20240101_000001', $exception->getMessage());
    }

    #[Test]
    public function migrationFileInvalidContainsPath(): void
    {
        $exception = DatabaseException::migrationFileInvalid('/migrations/bad_file.php');

        self::assertSame(
            'Migration file "/migrations/bad_file.php" must return a MigrationInterface instance',
            $exception->getMessage(),
        );
    }
}
