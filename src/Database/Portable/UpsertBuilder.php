<?php

declare(strict_types=1);

namespace Pulsar\Database\Portable;

use Pulsar\Api\Api;
use Pulsar\Database\Driver;

use function array_map;
use function implode;
use function sprintf;

/**
 * Builds portable upsert (INSERT ... ON CONFLICT / ON DUPLICATE KEY) SQL.
 *
 * PostgreSQL/SQLite: INSERT INTO ... ON CONFLICT (...) DO UPDATE SET col = EXCLUDED.col
 * MySQL:            INSERT INTO ... ON DUPLICATE KEY UPDATE col = VALUES(col)
 * @api
 */
#[Api(since: '1.0.0')]
final class UpsertBuilder
{
    /**
     * Build a portable upsert SQL statement.
     *
     * @param Driver       $driver          Database driver
     * @param string       $table           Table name
     * @param list<string> $columns         All columns being inserted
     * @param list<string> $conflictColumns Columns for conflict detection
     * @param list<string> $updateColumns   Columns to update on conflict
     * @param string       $extraWhere      Optional extra WHERE clause for the UPDATE (without leading WHERE/AND)
     * @param string       $extraSet        Optional extra SET expressions (e.g., "version = table.version + 1")
     */
    public static function compile(
        Driver $driver,
        string $table,
        array $columns,
        array $conflictColumns,
        array $updateColumns,
        string $extraWhere = '',
        string $extraSet = '',
    ): string {
        $q = self::quoteChar($driver);

        $placeholders = implode(', ', array_map(
            static fn(string $col): string => ':' . $col,
            $columns,
        ));

        $columnList = implode(', ', array_map(
            static fn(string $col): string => $q . $col . $q,
            $columns,
        ));

        $insertPart = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $q . $table . $q,
            $columnList,
            $placeholders,
        );

        return match ($driver) {
            Driver::PostgreSQL, Driver::SQLite => self::compilePostgresUpsert(
                $driver,
                $insertPart,
                $conflictColumns,
                $updateColumns,
                $extraWhere,
                $extraSet,
            ),
            Driver::MySQL => self::compileMysqlUpsert(
                $driver,
                $insertPart,
                $updateColumns,
                $extraSet,
            ),
        };
    }

    /**
     * @param list<string> $conflictColumns
     * @param list<string> $updateColumns
     */
    private static function compilePostgresUpsert(
        Driver $driver,
        string $insertPart,
        array $conflictColumns,
        array $updateColumns,
        string $extraWhere,
        string $extraSet,
    ): string {
        $q = self::quoteChar($driver);

        $conflictList = implode(', ', array_map(
            static fn(string $col): string => $q . $col . $q,
            $conflictColumns,
        ));

        $setClauses = array_map(
            static fn(string $col): string => sprintf('%s%s%s = EXCLUDED.%s%s%s', $q, $col, $q, $q, $col, $q),
            $updateColumns,
        );

        if ($extraSet !== '') {
            $setClauses[] = $extraSet;
        }

        $sql = sprintf(
            '%s ON CONFLICT (%s) DO UPDATE SET %s',
            $insertPart,
            $conflictList,
            implode(', ', $setClauses),
        );

        if ($extraWhere !== '') {
            $sql .= ' WHERE ' . $extraWhere;
        }

        return $sql;
    }

    /** @param list<string> $updateColumns */
    private static function compileMysqlUpsert(
        Driver $driver,
        string $insertPart,
        array $updateColumns,
        string $extraSet,
    ): string {
        $q = self::quoteChar($driver);

        $setClauses = array_map(
            static fn(string $col): string => sprintf('%s%s%s = VALUES(%s%s%s)', $q, $col, $q, $q, $col, $q),
            $updateColumns,
        );

        if ($extraSet !== '') {
            $setClauses[] = $extraSet;
        }

        return sprintf(
            '%s ON DUPLICATE KEY UPDATE %s',
            $insertPart,
            implode(', ', $setClauses),
        );
    }

    /**
     * Return the identifier quote character for the given driver.
     */
    private static function quoteChar(Driver $driver): string
    {
        return match ($driver) {
            Driver::MySQL => '`',
            Driver::PostgreSQL, Driver::SQLite => '"',
        };
    }
}
