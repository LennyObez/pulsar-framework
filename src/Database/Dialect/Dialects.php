<?php

declare(strict_types=1);

namespace Pulsar\Database\Dialect;

use Pulsar\Api\Api;
use Pulsar\Database\Driver;
use Pulsar\Database\DriverVariant;

/**
 * The one place a driver becomes a dialect.
 *
 * Every other `match ($driver)` in the framework is debt; this one is the design. Keeping
 * the mapping in a single method means a new engine is added here and nowhere else, and
 * that a second copy cannot drift from it — the failure that produced three disagreeing
 * definitions of composition-root membership elsewhere in this codebase.
 *
 * The variant matters as much as the driver. MariaDB reaches PDO through the MySQL driver
 * and is distinguished only by its `VERSION()` string, so resolving on the driver alone
 * hands a real MariaDB server the MySQL dialect — which is how a complete and tested
 * MariaDB dialect came to be unreachable.
 *
 * @api
 */
#[Api(since: '1.0.0')]
final class Dialects
{
    /**
     * Resolve the dialect for an engine.
     *
     * The variant defaults to Standard so a caller that genuinely does not know can still
     * ask. Prefer passing the variant the server reported: with MySQL and Standard, a
     * MariaDB server is served MySQL's dialect and quietly loses `RETURNING` and
     * `CREATE INDEX IF NOT EXISTS`.
     */
    public static function for(Driver $driver, DriverVariant $variant = DriverVariant::Standard): DialectInterface
    {
        return match ($driver) {
            Driver::MySQL => $variant === DriverVariant::MariaDb
                ? new MariaDbDialect()
                // Percona is MySQL for every purpose this interface describes: it
                // differs in server internals, not in the SQL it accepts.
                : new MySqlDialect(),
            Driver::PostgreSQL => new PostgreSqlDialect(),
            Driver::SQLite => new SqliteDialect(),
        };
    }
}
