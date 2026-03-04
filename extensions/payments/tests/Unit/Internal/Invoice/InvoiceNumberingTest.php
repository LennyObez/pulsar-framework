<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Internal\Invoice;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Payments\Internal\Invoice\InvoiceNumbering;

final class InvoiceNumberingTest extends TestCase
{
    #[Test]
    public function nextReturnsFormattedInvoiceNumberPostgres(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::PostgreSQL);

        $connection->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('RETURNING'),
                self::callback(fn(array $bindings): bool => isset($bindings['year'])),
            )
            ->willReturn(new Result([new Row(['current_number' => 42])]));

        $numbering = new InvoiceNumbering($connection);
        $result = $numbering->next();

        $year = new DateTimeImmutable()->format('Y');
        self::assertSame("INV-{$year}-000042", $result);
    }

    #[Test]
    public function postgresUsesReturningClauseForAtomicity(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::PostgreSQL);

        // The key assertion: PostgreSQL path uses a single query with RETURNING,
        // not a separate INSERT + SELECT
        $connection->expects(self::once())
            ->method('query')
            ->with(self::stringContains('RETURNING next_number - 1 AS current_number'))
            ->willReturn(new Result([new Row(['current_number' => 1])]));

        // Should NOT use execute() separately (that was the race condition)
        $connection->expects(self::never())->method('execute');

        $numbering = new InvoiceNumbering($connection);
        $result = $numbering->next();

        $year = new DateTimeImmutable()->format('Y');
        self::assertSame("INV-{$year}-000001", $result);
    }

    #[Test]
    public function nextReturnsFirstInvoiceWhenNoRowExistsMysql(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);

        // transaction() should be called (wraps in SELECT FOR UPDATE)
        $connection->expects(self::once())
            ->method('transaction')
            ->willReturnCallback(function (callable $callback) use ($connection): mixed {
                return $callback($connection);
            });

        // SELECT ... FOR UPDATE returns no rows (first invoice of the year)
        $connection->expects(self::once())
            ->method('query')
            ->with(self::stringContains('FOR UPDATE'))
            ->willReturn(new Result([]));

        // INSERT the first row
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::stringContains('INSERT INTO invoice_sequences'),
                self::callback(fn(array $b): bool => isset($b['year'])),
            );

        $numbering = new InvoiceNumbering($connection);
        $result = $numbering->next();

        $year = new DateTimeImmutable()->format('Y');
        self::assertSame("INV-{$year}-000001", $result);
    }

    #[Test]
    public function nextIncrementsExistingSequenceMysql(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);

        $connection->expects(self::once())
            ->method('transaction')
            ->willReturnCallback(function (callable $callback) use ($connection): mixed {
                return $callback($connection);
            });

        // SELECT ... FOR UPDATE returns existing row
        $connection->expects(self::once())
            ->method('query')
            ->with(self::stringContains('FOR UPDATE'))
            ->willReturn(new Result([new Row(['next_number' => 7])]));

        // UPDATE to increment
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::stringContains('UPDATE invoice_sequences'),
                self::callback(fn(array $b): bool => $b['next'] === 8),
            );

        $numbering = new InvoiceNumbering($connection);
        $result = $numbering->next();

        $year = new DateTimeImmutable()->format('Y');
        self::assertSame("INV-{$year}-000007", $result);
    }

    #[Test]
    public function mysqlUsesTransactionWithForUpdate(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);

        // The key assertion: MySQL path uses transaction() for atomicity
        $connection->expects(self::once())
            ->method('transaction')
            ->willReturnCallback(function (callable $callback) use ($connection): mixed {
                return $callback($connection);
            });

        $connection->method('query')->willReturn(new Result([new Row(['next_number' => 1])]));
        $connection->method('execute')->willReturn(1);

        $numbering = new InvoiceNumbering($connection);
        $result = $numbering->next();

        $year = new DateTimeImmutable()->format('Y');
        self::assertSame("INV-{$year}-000001", $result);
    }

    #[Test]
    public function sqliteUsesTransactionWithForUpdate(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);

        // SQLite follows the same path as MySQL (transaction + FOR UPDATE)
        $connection->expects(self::once())
            ->method('transaction')
            ->willReturnCallback(function (callable $callback) use ($connection): mixed {
                return $callback($connection);
            });

        $connection->method('query')->willReturn(new Result([new Row(['next_number' => 5])]));
        $connection->method('execute')->willReturn(1);

        $numbering = new InvoiceNumbering($connection);
        $result = $numbering->next();

        $year = new DateTimeImmutable()->format('Y');
        self::assertSame("INV-{$year}-000005", $result);
    }

    #[Test]
    public function invoiceNumberFormatIsCorrectlyZeroPadded(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::PostgreSQL);

        $connection->method('query')
            ->willReturn(new Result([new Row(['current_number' => 1])]));

        $numbering = new InvoiceNumbering($connection);
        $result = $numbering->next();

        self::assertMatchesRegularExpression('/^INV-\d{4}-\d{6}$/', $result);
    }

    #[Test]
    public function postgresDefaultsToOneWhenQueryReturnsNull(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::PostgreSQL);

        $connection->method('query')->willReturn(new Result([]));

        $numbering = new InvoiceNumbering($connection);
        $result = $numbering->next();

        $year = new DateTimeImmutable()->format('Y');
        self::assertSame("INV-{$year}-000001", $result);
    }
}
