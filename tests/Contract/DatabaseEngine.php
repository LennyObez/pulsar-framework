<?php

declare(strict_types=1);

namespace Pulsar\Tests\Contract;

use PDO;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;
use RuntimeException;

use function getenv;
use function sprintf;
use function strtoupper;
use function trim;

/**
 * Resolves which database engines this run can actually talk to.
 *
 * The framework supports SQLite, MySQL and PostgreSQL. SQLite needs nothing; the other
 * two need a server, and whether one is present is a property of the environment rather
 * than of the code. This class answers that question once, in one place, so that every
 * contract test asks it the same way.
 *
 * ## Configured-but-unreachable is a failure, never a skip
 *
 * The distinction this class exists to enforce. An engine that was never configured is
 * genuinely absent and its tests skip with a reason. An engine that WAS configured and
 * then cannot be reached is a broken environment pretending to be an empty one — and if
 * that skipped quietly, a run with a dead server would be indistinguishable from a run
 * with no server, which is how a suite comes to report success while exercising nothing.
 *
 * ## Environment
 *
 * Per engine, with a fallback to the shared values so a single set covers both when the
 * credentials happen to match:
 *
 *   DB_MYSQL_HOST, DB_MYSQL_PORT, DB_MYSQL_DATABASE, DB_MYSQL_USERNAME, DB_MYSQL_PASSWORD
 *   DB_PGSQL_HOST, DB_PGSQL_PORT, DB_PGSQL_DATABASE, DB_PGSQL_USERNAME, DB_PGSQL_PASSWORD
 *   DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD
 *
 * The port falls back to the driver's default, so a host alone is enough to configure an
 * engine listening where it normally does.
 */
final class DatabaseEngine
{
    /**
     * Every engine the framework claims to support.
     *
     * Iterating the enum rather than a hand-written list means a driver added to
     * {@see Driver} is one this contract immediately demands coverage for.
     *
     * @return list<Driver>
     */
    public static function all(): array
    {
        return Driver::cases();
    }

    /**
     * Whether this engine has been given somewhere to connect to.
     *
     * SQLite is always available: it is a library, not a server.
     */
    public static function isConfigured(Driver $driver): bool
    {
        return $driver === Driver::SQLite || self::value($driver, 'HOST') !== null;
    }

    /**
     * Why an engine is not being exercised, phrased so the reason survives into the
     * skip ledger and can be read months later without the surrounding context.
     */
    public static function absenceReason(Driver $driver): string
    {
        return sprintf(
            'No %s server configured: set DB_%s_HOST (or DB_HOST) to exercise the %s dialect. '
            . 'Without it this dialect is only ever compared to a string, never executed.',
            $driver->value,
            strtoupper($driver->value),
            $driver->value,
        );
    }

    /**
     * Open a connection, or fail loudly.
     *
     * @throws RuntimeException If the engine is configured but cannot be reached — the
     *                          case that must never be mistaken for absence.
     */
    public static function connect(Driver $driver): ConnectionInterface
    {
        if ($driver === Driver::SQLite) {
            return new PdoConnection('contract', $driver, 'sqlite::memory:', null, null);
        }

        $host = self::value($driver, 'HOST');

        if ($host === null) {
            throw new RuntimeException(self::absenceReason($driver));
        }

        $connection = new PdoConnection(
            'contract',
            $driver,
            $driver->buildDsn(
                $host,
                (int) (self::value($driver, 'PORT') ?? (string) $driver->defaultPort()),
                self::value($driver, 'DATABASE') ?? 'pulsar_test',
            ),
            self::value($driver, 'USERNAME'),
            self::value($driver, 'PASSWORD'),
            [PDO::ATTR_TIMEOUT => 5],
        );

        // Force the connection now rather than at first use, so a dead server is reported
        // here — with the engine named — instead of inside whichever assertion happened
        // to touch the database first.
        $connection->query('SELECT 1');

        return $connection;
    }

    /**
     * Read `DB_<DRIVER>_<KEY>`, then `DB_<KEY>`. An empty string counts as unset:
     * a variable exported without a value configures nothing.
     */
    private static function value(Driver $driver, string $key): ?string
    {
        foreach (['DB_' . strtoupper($driver->value) . '_' . $key, 'DB_' . $key] as $name) {
            $raw = getenv($name);

            if ($raw !== false && trim($raw) !== '') {
                return $raw;
            }
        }

        return null;
    }
}
