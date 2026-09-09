<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConnectionConfig;
use Pulsar\Config\DatabaseConfig;
use Pulsar\Database\ConnectionManager;
use Pulsar\Database\Driver;
use Pulsar\Database\Exception\DatabaseException;
use Pulsar\Database\Routing\ReadWriteConfig;
use Pulsar\Database\Routing\ReadWriteConnections;
use Pulsar\Database\Routing\ReadWriteRouter;
use Pulsar\Database\Routing\RoutingConnectionManager;
use Pulsar\Database\Routing\StickinessContext;

#[CoversClass(ReadWriteConnections::class)]
final class ReadWriteConnectionsTest extends TestCase
{
    /**
     * The configuration in `config/database.php` and in `docs/database.md`, wired the
     * way `DatabaseWiring` wires it, must be able to open a replica connection.
     *
     * Before this class existed, `read_hosts` entries went straight to
     * `ConnectionManagerInterface::connection($name)`, which looks up the `connections`
     * map. `replica-1.db.internal` is not a key in that map, so the documented setup
     * threw `Database connection "replica-1.db.internal" is not configured` the first
     * time a read was routed — read/write routing could not resolve a connection at all.
     */
    #[Test]
    public function theDocumentedConfigurationResolvesAReplicaConnection(): void
    {
        $resolved = ReadWriteConnections::resolve(
            $this->databaseConfig(),
            new ReadWriteConfig(
                readHosts: ['replica-1.db.internal', 'replica-2.db.internal'],
                writeHost: 'primary.db.internal',
                enabled: true,
            ),
        );

        $manager = new RoutingConnectionManager(
            ConnectionManager::fromConfig($resolved->database),
            new ReadWriteRouter(),
            new StickinessContext(),
            $resolved->routing,
        );

        // PdoConnection is lazy, so this resolves a connection without opening a socket.
        self::assertSame(
            'read:replica-1.db.internal',
            $manager->connectionForQuery('SELECT * FROM users')->name(),
        );
        self::assertSame(
            'read:replica-2.db.internal',
            $manager->connectionForQuery('SELECT * FROM orders')->name(),
        );
    }

    /**
     * A derived replica is the primary with the host swapped: same driver, port,
     * database, credentials, charset and driver options. Anything else would be a
     * different database wearing the word "replica".
     */
    #[Test]
    public function aDerivedReplicaInheritsEverythingButTheHost(): void
    {
        $resolved = ReadWriteConnections::resolve(
            $this->databaseConfig(),
            new ReadWriteConfig(readHosts: ['replica-1.db.internal'], enabled: true),
        );

        $replica = $resolved->database->connection('read:replica-1.db.internal');
        $primary = $resolved->database->connection('mysql');

        self::assertSame('replica-1.db.internal', $replica->host);
        self::assertSame($primary->driver, $replica->driver);
        self::assertSame($primary->port, $replica->port);
        self::assertSame($primary->database, $replica->database);
        self::assertSame($primary->username, $replica->username);
        self::assertSame($primary->password, $replica->password);
        self::assertSame($primary->charset, $replica->charset);
        self::assertSame($primary->collation, $replica->collation);
        self::assertSame($primary->options, $replica->options);
    }

    /**
     * The derived connections join `connections`, so anything auditing the configured
     * connections — a TLS verifier among them — sees the replicas rather than only the
     * primary an operator happened to write down.
     */
    #[Test]
    public function derivedConnectionsAreVisibleInTheConfiguredConnections(): void
    {
        $resolved = ReadWriteConnections::resolve(
            $this->databaseConfig(),
            new ReadWriteConfig(
                readHosts: ['replica-1.db.internal'],
                writeHost: 'primary.db.internal',
                enabled: true,
            ),
        );

        self::assertSame(
            ['mysql', 'write:primary.db.internal', 'read:replica-1.db.internal'],
            array_keys($resolved->database->connections),
        );
    }

    /**
     * An entry that names a configured connection is that connection, credentials and
     * all. This is how a replica reached with a read-only user is configured.
     */
    #[Test]
    public function anEntryNamingAConfiguredConnectionIsUsedVerbatim(): void
    {
        $config = $this->databaseConfig([
            'reporting' => new ConnectionConfig(
                name: 'reporting',
                driver: Driver::MySQL,
                host: 'reporting.db.internal',
                port: 3306,
                database: 'pulsar',
                username: 'readonly',
                password: 'other',
                charset: 'utf8mb4',
                collation: 'utf8mb4_unicode_ci',
                options: [],
            ),
        ]);

        $resolved = ReadWriteConnections::resolve(
            $config,
            new ReadWriteConfig(readHosts: ['reporting'], enabled: true),
        );

        self::assertSame(['reporting'], $resolved->routing->readHosts);
        self::assertArrayNotHasKey('read:reporting', $resolved->database->connections);
        self::assertSame('readonly', $resolved->database->connection('reporting')->username);
    }

    /**
     * `write_host` selects the connection writes go to, which is what makes it govern
     * anything: the plain manager, the migration runner and the seeder all reach the
     * primary through the default connection.
     */
    #[Test]
    public function aWriteHostBecomesTheDefaultConnection(): void
    {
        $resolved = ReadWriteConnections::resolve(
            $this->databaseConfig(),
            new ReadWriteConfig(writeHost: 'primary.db.internal', enabled: true),
        );

        self::assertSame('write:primary.db.internal', $resolved->database->defaultConnection);
        self::assertSame(
            'primary.db.internal',
            $resolved->database->connection('write:primary.db.internal')->host,
        );
    }

    #[Test]
    public function anEmptyWriteHostLeavesTheDefaultConnectionAlone(): void
    {
        $resolved = ReadWriteConnections::resolve(
            $this->databaseConfig(),
            new ReadWriteConfig(readHosts: ['replica-1.db.internal'], enabled: true),
        );

        self::assertSame('mysql', $resolved->database->defaultConnection);
    }

    /**
     * The operator's own `read_write` block is returned unchanged on the config, so a
     * report of the configuration reports what is in the file. Only the copy handed to
     * the manager carries connection names.
     */
    #[Test]
    public function theOperatorsOwnReadWriteBlockIsNotRewritten(): void
    {
        $config = $this->databaseConfig();
        $routing = new ReadWriteConfig(readHosts: ['replica-1.db.internal'], enabled: true);

        $resolved = ReadWriteConnections::resolve($config, $routing);

        self::assertSame(['replica-1.db.internal'], $resolved->database->readWrite->readHosts);
        self::assertSame(['read:replica-1.db.internal'], $resolved->routing->readHosts);
    }

    /**
     * A `sqlite:` DSN carries a file path and no host, so a derived "replica" would be
     * the same file under a different name: reads reported as replica traffic that are
     * really the primary. Refused rather than accepted quietly.
     */
    #[Test]
    public function sqliteIsRefusedRatherThanPointedBackAtTheSameFile(): void
    {
        $config = new DatabaseConfig(
            defaultConnection: 'sqlite',
            connections: [
                'sqlite' => new ConnectionConfig(
                    name: 'sqlite',
                    driver: Driver::SQLite,
                    host: '',
                    port: 0,
                    database: 'database/pulsar.sqlite',
                    username: '',
                    password: '',
                    charset: 'utf8',
                    collation: '',
                    options: [],
                ),
            ],
            migrationsTable: 'pulsar_migrations',
            migrationsPath: 'database/migrations',
        );

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/addresses no host/');

        ReadWriteConnections::resolve(
            $config,
            new ReadWriteConfig(readHosts: ['replica-1.db.internal'], enabled: true),
        );
    }

    #[Test]
    public function aMissingDefaultConnectionIsReportedRatherThanDerivedFrom(): void
    {
        $config = new DatabaseConfig(
            defaultConnection: 'mysql',
            connections: [],
            migrationsTable: 'pulsar_migrations',
            migrationsPath: 'database/migrations',
        );

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/"mysql" is not configured/');

        ReadWriteConnections::resolve(
            $config,
            new ReadWriteConfig(readHosts: ['replica-1.db.internal'], enabled: true),
        );
    }

    /**
     * An entry naming the primary's own host is the primary, not a second connection to
     * the same server. Writing `write_host` down explicitly is common, and doubling the
     * connection count for it would buy nothing.
     */
    #[Test]
    public function anEntryNamingThePrimarysOwnHostIsThePrimary(): void
    {
        $resolved = ReadWriteConnections::resolve(
            $this->databaseConfig(),
            new ReadWriteConfig(readHosts: ['127.0.0.1'], writeHost: '127.0.0.1', enabled: true),
        );

        self::assertSame('mysql', $resolved->database->defaultConnection);
        self::assertSame(['mysql'], $resolved->routing->readHosts);
        self::assertSame(['mysql'], array_keys($resolved->database->connections));
    }

    /**
     * @param array<string, ConnectionConfig> $extra
     */
    private function databaseConfig(array $extra = []): DatabaseConfig
    {
        return new DatabaseConfig(
            defaultConnection: 'mysql',
            connections: [
                'mysql' => new ConnectionConfig(
                    name: 'mysql',
                    driver: Driver::MySQL,
                    host: '127.0.0.1',
                    port: 3306,
                    database: 'pulsar',
                    username: 'root',
                    password: 'secret',
                    charset: 'utf8mb4',
                    collation: 'utf8mb4_unicode_ci',
                    options: [],
                ),
                ...$extra,
            ],
            migrationsTable: 'pulsar_migrations',
            migrationsPath: 'database/migrations',
            readWrite: new ReadWriteConfig(
                readHosts: ['replica-1.db.internal'],
                writeHost: 'primary.db.internal',
                enabled: true,
            ),
        );
    }
}
