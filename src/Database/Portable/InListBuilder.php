<?php

declare(strict_types=1);

namespace Pulsar\Database\Portable;

use InvalidArgumentException;
use Pulsar\Api\Api;
use Pulsar\Database\Driver;

use function implode;
use function sprintf;

/**
 * Builds portable IN-list or ANY() clauses.
 *
 * PostgreSQL: "column = ANY(:param)" with array parameter
 * MySQL/SQLite: "column IN (:param_0, :param_1, ...)" with expanded parameters
 */
#[Api(since: '1.0.0')]
final class InListBuilder
{
    /**
     * Build a portable IN-list or ANY() clause.
     *
     * @param Driver $driver    Database driver
     * @param string $column    Column name to match
     * @param string $paramName Parameter name (without colon prefix)
     * @param int    $count     Number of values in the list
     */
    public static function compile(Driver $driver, string $column, string $paramName, int $count): string
    {
        if ($count <= 0) {
            throw new InvalidArgumentException('InListBuilder::compile() requires at least one value');
        }

        return match ($driver) {
            Driver::PostgreSQL => sprintf('%s = ANY(:%s)', $column, $paramName),
            Driver::MySQL, Driver::SQLite => self::compileInList($column, $paramName, $count),
        };
    }

    /**
     * Expand parameters for the IN-list clause.
     *
     * PostgreSQL: returns single parameter with PostgreSQL array literal.
     * MySQL/SQLite: returns indexed parameters (:param_0, :param_1, ...).
     *
     * @param Driver       $driver    Database driver
     * @param string       $paramName Parameter name (without colon prefix)
     * @param list<string> $values    Values to bind
     *
     * @return array<string, mixed> Bindings keyed by parameter name (without colon)
     */
    public static function expandParams(Driver $driver, string $paramName, array $values): array
    {
        if ($values === []) {
            throw new InvalidArgumentException('InListBuilder::expandParams() requires at least one value');
        }

        if ($driver === Driver::PostgreSQL) {
            return [$paramName => '{' . implode(',', $values) . '}'];
        }

        $bindings = [];

        foreach ($values as $i => $value) {
            $bindings[$paramName . '_' . $i] = $value;
        }

        return $bindings;
    }

    private static function compileInList(string $column, string $paramName, int $count): string
    {
        $placeholders = [];

        for ($i = 0; $i < $count; $i++) {
            $placeholders[] = ':' . $paramName . '_' . $i;
        }

        return sprintf('%s IN (%s)', $column, implode(', ', $placeholders));
    }
}
