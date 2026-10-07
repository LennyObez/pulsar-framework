<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Support;

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\PdoConnection;
use RuntimeException;

use function array_reverse;
use function dirname;
use function glob;
use function is_object;
use function sort;

use const DIRECTORY_SEPARATOR;

/**
 * An in-memory database carrying the AI governance schema, built by the shipped
 * migration.
 *
 * The DDL is deliberately not duplicated here. A test fixture that wrote its own
 * `CREATE TABLE` would pass against a schema the migration never produces, which
 * is the failure mode ADR-0041 recorded for the token vault: a store bound
 * against a database holding no table for it, with every binding reading clean.
 * Loading the migration file means a column the migration forgets is a column
 * these tests do not have either.
 *
 * EVERY MIGRATION IN `src/Migration` IS RUN, discovered rather than listed. The
 * helper used to name one file, so the second migration this extension shipped
 * would have been invisible to every test using it — the fixture would have gone
 * on passing against a schema missing a table, which is the same defect one
 * hand-written `CREATE TABLE` would have introduced. Discovery is by the filename
 * ordering the migration runner itself uses, so a table created by a later
 * migration cannot be built before the one it follows.
 */
final readonly class AiGovernanceSchema
{
    /**
     * All static; there is nothing to hold.
     */
    private function __construct() {}

    /**
     * A fresh in-memory SQLite connection with the schema applied.
     */
    public static function connection(): PdoConnection
    {
        $connection = new PdoConnection(
            connectionName: 'ai-governance-test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );

        self::apply($connection);

        return $connection;
    }

    /**
     * Run the shipped migration against a connection.
     */
    public static function apply(ConnectionInterface $connection): void
    {
        foreach (self::migrations() as $migration) {
            $migration->up($connection);
        }
    }

    /**
     * Reverse them, so a test can establish that `down()` is real.
     *
     * In the reverse of the order they were applied, which is what a migration
     * runner does and the only order that stays correct once one migration's
     * table depends on another's.
     */
    public static function drop(ConnectionInterface $connection): void
    {
        foreach (array_reverse(self::migrations()) as $migration) {
            $migration->down($connection);
        }
    }

    /**
     * Every shipped migration, in the order the runner would apply them.
     *
     * @return list<MigrationInterface>
     */
    private static function migrations(): array
    {
        $directory = dirname(__DIR__, 2)
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . 'Migration';

        $paths = glob($directory . DIRECTORY_SEPARATOR . '2*.php');

        if ($paths === false || $paths === []) {
            throw new RuntimeException(
                'No AI governance migrations were found under: ' . $directory,
            );
        }

        // The runner orders by the timestamp prefix, and so does this: a fixture
        // that applied them in directory order would build a schema no deployment
        // ever gets.
        sort($paths);

        $migrations = [];

        foreach ($paths as $path) {
            /** @var mixed $migration */
            $migration = require $path;

            if (! is_object($migration) || ! $migration instanceof MigrationInterface) {
                throw new RuntimeException(
                    'An AI governance migration did not return a MigrationInterface: ' . $path,
                );
            }

            $migrations[] = $migration;
        }

        return $migrations;
    }
}
