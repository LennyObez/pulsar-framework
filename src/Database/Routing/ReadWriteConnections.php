<?php

declare(strict_types=1);

namespace Pulsar\Database\Routing;

use Pulsar\Api\Internal;
use Pulsar\Config\ConnectionConfig;
use Pulsar\Config\DatabaseConfig;
use Pulsar\Database\Driver;
use Pulsar\Database\Exception\DatabaseException;

/**
 * Turns the hosts written in `read_write` into connections the manager can open.
 *
 * `config/database.php` and the documentation both describe `read_hosts` and
 * `write_host` as HOSTS:
 *
 * ```php
 * 'read_write' => [
 *     'enabled' => true,
 *     'write_host' => 'primary.db.internal',
 *     'read_hosts' => ['replica-1.db.internal', 'replica-2.db.internal'],
 * ],
 * ```
 *
 * {@see RoutingConnectionManager} passed those strings straight to
 * `ConnectionManagerInterface::connection($name)`, which looks up the `connections`
 * map in the same file. `replica-1.db.internal` is not a key in that map, so a
 * deployment wired and configured exactly as documented threw
 * `DatabaseException::connectionNotConfigured()` the first time a read was routed.
 * The manager was never wrong to ask for a connection by name; nothing had turned the
 * hosts into connections.
 *
 * That is what this does, in the composition root, before the manager is built. Each
 * entry is resolved in one of two ways, in this order:
 *
 *  1. **An existing connection name.** `'read_hosts' => ['reporting']` with a
 *     `reporting` entry under `connections` uses that entry verbatim, credentials and
 *     all. This is how a replica reached with a different user or a different database
 *     is configured.
 *  2. **A host.** Anything else is a hostname, and gets a connection derived from the
 *     default one with the host replaced — same driver, port, database, credentials,
 *     charset and driver options, which is what "a replica of this database" means.
 *     The derived entry is registered under `read:<host>` / `write:<host>` and added
 *     to the returned {@see DatabaseConfig}, so anything that audits the configured
 *     connections (TLS verifiers among them) sees the replicas too.
 *
 * A driver whose DSN has no host cannot express a replica, so SQLite is refused rather
 * than silently pointed back at the same file.
 *
 * The returned {@see DatabaseConfig} has `defaultConnection` pointing at the write
 * connection, which is what makes `write_host` govern anything at all: the plain
 * manager, the migration runner and the seeder all reach the primary through the
 * default. Its `readWrite` property is left exactly as the operator wrote it, hosts and
 * all, so anything reporting the configuration back reports what is in the file; the
 * rewritten copy is the one handed to the manager.
 */
#[Internal(reason: 'Composition-root translation of read_write hosts into connections')]
final readonly class ReadWriteConnections
{
    private function __construct(
        /** The database configuration with every derived replica/primary connection added. */
        public DatabaseConfig $database,
        /** The routing configuration with `readHosts` rewritten to connection names. */
        public ReadWriteConfig $routing,
    ) {}

    /**
     * @throws DatabaseException If the default connection is missing, or its driver
     *                           has no host for a replica to differ in.
     */
    public static function resolve(DatabaseConfig $database, ReadWriteConfig $routing): self
    {
        $connections = $database->connections;
        $primary = $connections[$database->defaultConnection]
            ?? throw DatabaseException::connectionNotConfigured($database->defaultConnection);

        $writeConnection = $routing->writeHost === ''
            ? $database->defaultConnection
            : self::connectionFor(
                $routing->writeHost,
                'write',
                $primary,
                $database->defaultConnection,
                $connections,
            );

        $readConnections = [];
        foreach ($routing->readHosts as $readHost) {
            $readConnections[] = self::connectionFor(
                $readHost,
                'read',
                $primary,
                $database->defaultConnection,
                $connections,
            );
        }

        return new self(
            new DatabaseConfig(
                defaultConnection: $writeConnection,
                connections: $connections,
                migrationsTable: $database->migrationsTable,
                migrationsPath: $database->migrationsPath,
                readWrite: $database->readWrite,
                failover: $database->failover,
                queryCache: $database->queryCache,
                monitor: $database->monitor,
                unknownKeys: $database->unknownKeys,
            ),
            new ReadWriteConfig(
                readHosts: $readConnections,
                writeHost: $routing->writeHost,
                stickyDuration: $routing->stickyDuration,
                enabled: $routing->enabled,
                unknownKeys: $routing->unknownKeys,
            ),
        );
    }

    /**
     * The connection name for one `read_hosts` / `write_host` entry, registering a
     * derived connection when the entry is a host rather than a configured name.
     *
     * @param string $primaryName The default connection's name, returned when the entry names its host
     * @param array<string, ConnectionConfig> $connections Mutated when a connection is derived
     * @throws DatabaseException
     */
    private static function connectionFor(
        string $entry,
        string $role,
        ConnectionConfig $primary,
        string $primaryName,
        array &$connections,
    ): string {
        if (isset($connections[$entry])) {
            return $entry;
        }

        if ($entry === $primary->host) {
            // The entry names the primary's own host, which an operator writing
            // `write_host` down explicitly often does. Deriving a second connection to
            // the same server would double the connection count for no replica: the
            // primary IS the host that was asked for.
            return $primaryName;
        }

        if ($primary->driver === Driver::SQLite) {
            // sqlite: DSNs carry a file path and no host, so a derived connection would
            // be byte-identical to the primary. Routing reads to it would report replica
            // traffic that is really the same file, which is worse than not routing.
            throw DatabaseException::readWriteRoutingUnsupportedDriver($primary->driver->value, $entry);
        }

        $name = $role . ':' . $entry;

        $connections[$name] = new ConnectionConfig(
            name: $name,
            driver: $primary->driver,
            host: $entry,
            port: $primary->port,
            database: $primary->database,
            username: $primary->username,
            password: $primary->password,
            charset: $primary->charset,
            collation: $primary->collation,
            options: $primary->options,
        );

        return $name;
    }
}
