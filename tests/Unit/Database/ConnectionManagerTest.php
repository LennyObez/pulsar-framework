<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConnectionConfig;
use Pulsar\Config\DatabaseConfig;
use Pulsar\Database\ConnectionManager;
use Pulsar\Database\Driver;
use Pulsar\Database\Exception\DatabaseException;

#[CoversClass(ConnectionManager::class)]
final class ConnectionManagerTest extends TestCase
{
    #[Test]
    public function getDefaultConnectionNameReturnsConfiguredDefault(): void
    {
        $config = new DatabaseConfig(
            defaultConnection: 'sqlite',
            connections: [],
            migrationsTable: 'migrations',
            migrationsPath: 'database/migrations',
        );

        $manager = ConnectionManager::fromConfig($config);

        self::assertSame('sqlite', $manager->getDefaultConnectionName());
    }

    #[Test]
    public function connectionThrowsForUnknownName(): void
    {
        $config = new DatabaseConfig(
            defaultConnection: 'mysql',
            connections: [],
            migrationsTable: 'migrations',
            migrationsPath: 'database/migrations',
        );

        $manager = ConnectionManager::fromConfig($config);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageIsOrContains('Database connection "unknown" is not configured');

        $manager->connection('unknown');
    }

    #[Test]
    public function connectionDefaultsToDefaultConnectionName(): void
    {
        $config = new DatabaseConfig(
            defaultConnection: 'sqlite',
            connections: [
                'sqlite' => new ConnectionConfig(
                    name: 'sqlite',
                    driver: Driver::SQLite,
                    host: '',
                    port: 0,
                    database: ':memory:',
                    username: '',
                    password: '',
                    charset: 'utf8',
                    collation: '',
                    options: [],
                ),
            ],
            migrationsTable: 'migrations',
            migrationsPath: 'database/migrations',
        );

        $manager = ConnectionManager::fromConfig($config);

        // Should not throw — resolves default "sqlite" connection
        $connection = $manager->connection();
        self::assertSame('sqlite', $connection->name());
    }

    #[Test]
    public function connectionCachesInstances(): void
    {
        $config = new DatabaseConfig(
            defaultConnection: 'sqlite',
            connections: [
                'sqlite' => new ConnectionConfig(
                    name: 'sqlite',
                    driver: Driver::SQLite,
                    host: '',
                    port: 0,
                    database: ':memory:',
                    username: '',
                    password: '',
                    charset: 'utf8',
                    collation: '',
                    options: [],
                ),
            ],
            migrationsTable: 'migrations',
            migrationsPath: 'database/migrations',
        );

        $manager = ConnectionManager::fromConfig($config);

        $conn1 = $manager->connection('sqlite');
        $conn2 = $manager->connection('sqlite');

        self::assertSame($conn1, $conn2);
    }

    #[Test]
    public function disconnectRemovesCachedConnection(): void
    {
        $config = new DatabaseConfig(
            defaultConnection: 'sqlite',
            connections: [
                'sqlite' => new ConnectionConfig(
                    name: 'sqlite',
                    driver: Driver::SQLite,
                    host: '',
                    port: 0,
                    database: ':memory:',
                    username: '',
                    password: '',
                    charset: 'utf8',
                    collation: '',
                    options: [],
                ),
            ],
            migrationsTable: 'migrations',
            migrationsPath: 'database/migrations',
        );

        $manager = ConnectionManager::fromConfig($config);

        $conn1 = $manager->connection('sqlite');
        $manager->disconnect('sqlite');
        $conn2 = $manager->connection('sqlite');

        self::assertNotSame($conn1, $conn2);
    }

    #[Test]
    public function disconnectAllClearsAllCachedConnections(): void
    {
        $config = new DatabaseConfig(
            defaultConnection: 'sqlite',
            connections: [
                'sqlite' => new ConnectionConfig(
                    name: 'sqlite',
                    driver: Driver::SQLite,
                    host: '',
                    port: 0,
                    database: ':memory:',
                    username: '',
                    password: '',
                    charset: 'utf8',
                    collation: '',
                    options: [],
                ),
            ],
            migrationsTable: 'migrations',
            migrationsPath: 'database/migrations',
        );

        $manager = ConnectionManager::fromConfig($config);

        $conn1 = $manager->connection('sqlite');
        $manager->disconnect();
        $conn2 = $manager->connection('sqlite');

        self::assertNotSame($conn1, $conn2);
    }
}
