<?php

declare(strict_types=1);

namespace Pulsar\Testing\Database;

use PDO;
use PHPUnit\Framework\Assert;
use Pulsar\Api\Api;

use function get_debug_type;
use function implode;
use function is_scalar;
use function sprintf;

/**
 * Database assertion methods for integration tests.
 *
 * Provides assertDatabaseHas(), assertDatabaseMissing(), and assertDatabaseCount()
 * with detailed failure messages showing expected criteria vs actual records.
 *
 * Requires a `getConnection(): PDO` method on the using class.
 * @api
 */
#[Api(since: '1.0.0')]
trait DatabaseAssertions
{
    /**
     * Assert that a row matching the given criteria exists in the table.
     *
     * @param array<string, mixed> $criteria Column => value pairs
     */
    protected function assertDatabaseHas(string $table, array $criteria): void
    {
        $count = $this->countMatchingRows($table, $criteria);

        Assert::assertGreaterThan(
            0,
            $count,
            sprintf(
                'Expected table [%s] to contain a record matching %s, but no matching records were found.',
                $table,
                $this->formatCriteria($criteria),
            ),
        );
    }

    /**
     * Assert that no rows matching the given criteria exist in the table.
     *
     * @param array<string, mixed> $criteria Column => value pairs
     */
    protected function assertDatabaseMissing(string $table, array $criteria): void
    {
        $count = $this->countMatchingRows($table, $criteria);

        Assert::assertSame(
            0,
            $count,
            sprintf(
                'Expected table [%s] NOT to contain a record matching %s, but %d matching record(s) were found.',
                $table,
                $this->formatCriteria($criteria),
                $count,
            ),
        );
    }

    /**
     * Assert the exact number of rows in a table (optionally filtered by criteria).
     *
     * @param array<string, mixed> $criteria Column => value pairs (empty = count all)
     */
    protected function assertDatabaseCount(string $table, int $expected, array $criteria = []): void
    {
        $actual = $criteria === []
            ? $this->countAllRows($table)
            : $this->countMatchingRows($table, $criteria);

        Assert::assertSame(
            $expected,
            $actual,
            sprintf(
                'Expected table [%s] to contain %d record(s)%s, but found %d.',
                $table,
                $expected,
                $criteria !== [] ? sprintf(' matching %s', $this->formatCriteria($criteria)) : '',
                $actual,
            ),
        );
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private function countMatchingRows(string $table, array $criteria): int
    {
        $connection = $this->getDatabaseConnection();

        $conditions = [];
        $params = [];

        foreach ($criteria as $column => $value) {
            if ($value === null) {
                $conditions[] = sprintf('%s IS NULL', $column);
            } else {
                $conditions[] = sprintf('%s = :%s', $column, $column);
                $params[$column] = $value;
            }
        }

        $sql = sprintf(
            'SELECT COUNT(*) FROM %s WHERE %s',
            $table,
            implode(' AND ', $conditions),
        );

        $stmt = $connection->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    private function countAllRows(string $table): int
    {
        $connection = $this->getDatabaseConnection();
        $stmt = $connection->query(sprintf('SELECT COUNT(*) FROM %s', $table));

        return $stmt !== false ? (int) $stmt->fetchColumn() : 0;
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private function formatCriteria(array $criteria): string
    {
        $parts = [];

        foreach ($criteria as $column => $value) {
            $parts[] = sprintf(
                '%s=%s',
                $column,
                $value === null ? 'NULL' : (is_scalar($value) ? sprintf('"%s"', $value) : get_debug_type($value)),
            );
        }

        return '{' . implode(', ', $parts) . '}';
    }

    /**
     * Get the database connection for assertions.
     *
     * Implement this method in your test class to provide the PDO connection.
     */
    abstract protected function getDatabaseConnection(): PDO;
}
