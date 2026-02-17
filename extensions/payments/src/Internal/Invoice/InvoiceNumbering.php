<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Internal\Invoice;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;

use function sprintf;

/**
 * Sequential invoice numbering service.
 *
 * Generates invoice numbers in the format: INV-YYYY-NNNNNN
 * where YYYY is the year and NNNNNN is a zero-padded sequence number.
 *
 * Numbers are sequential and immutable per EU legal requirements.
 * The sequence is maintained in the database to prevent gaps
 * across application restarts and multiple instances.
 *
 * Uses driver-appropriate atomic operations:
 * - PostgreSQL: INSERT ... ON CONFLICT ... RETURNING (fully atomic)
 * - MySQL/SQLite: SELECT FOR UPDATE within a transaction (serialized)
 */
#[Internal]
final readonly class InvoiceNumbering
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    /**
     * Generate the next invoice number.
     *
     * This operation is atomic; concurrent calls will receive
     * unique sequential numbers regardless of database driver.
     */
    #[NoDiscard]
    public function next(): string
    {
        $year = new DateTimeImmutable()->format('Y');

        $number = match ($this->connection->driver()) {
            Driver::PostgreSQL => $this->nextPostgres($year),
            Driver::MySQL, Driver::SQLite => $this->nextMysql($year),
        };

        return sprintf('INV-%s-%06d', $year, $number);
    }

    /**
     * PostgreSQL: Use INSERT ... ON CONFLICT ... RETURNING for a fully atomic upsert.
     */
    private function nextPostgres(string $year): int
    {
        $result = $this->connection->query(
            <<<'SQL'
                INSERT INTO invoice_sequences (year, next_number)
                VALUES (:year, 2)
                ON CONFLICT (year) DO UPDATE SET next_number = invoice_sequences.next_number + 1
                RETURNING next_number - 1 AS current_number
                SQL,
            ['year' => $year],
        );

        return $result->first()?->getInt('current_number') ?? 1;
    }

    /**
     * MySQL/SQLite: Use a transaction with SELECT ... FOR UPDATE to serialize access.
     */
    private function nextMysql(string $year): int
    {
        return $this->connection->transaction(function (ConnectionInterface $conn) use ($year): int {
            // Lock the row for this year (SELECT ... FOR UPDATE)
            $result = $conn->query(
                'SELECT next_number FROM invoice_sequences WHERE year = :year FOR UPDATE',
                ['year' => $year],
            );

            $row = $result->first();

            if ($row === null) {
                // First invoice of the year: insert the initial row
                $conn->execute(
                    'INSERT INTO invoice_sequences (year, next_number) VALUES (:year, 2)',
                    ['year' => $year],
                );

                return 1;
            }

            $currentNumber = $row->getInt('next_number');

            $conn->execute(
                'UPDATE invoice_sequences SET next_number = :next WHERE year = :year',
                ['next' => $currentNumber + 1, 'year' => $year],
            );

            return $currentNumber;
        });
    }
}
