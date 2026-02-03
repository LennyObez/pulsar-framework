<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\DatabaseConfig;
use Pulsar\Config\Environment;
use Pulsar\Database\Driver;
use Pulsar\Database\Exception\DatabaseException;

#[CoversClass(DatabaseConfig::class)]
final class DatabaseConfigTest extends TestCase
{
    #[Test]
    public function fromArrayCreatesConfigWithDefaults(): void
    {
        $env = Environment::load(null);
        $config = DatabaseConfig::fromArray([
            'connections' => [
                'sqlite' => [
                    'driver' => 'sqlite',
                    'database' => ':memory:',
                ],
            ],
        ], $env);

        self::assertSame('mysql', $config->defaultConnection);
        self::assertCount(1, $config->connections);
        self::assertSame('pulsar_migrations', $config->migrationsTable);
        self::assertSame('database/migrations', $config->migrationsPath);
    }

    #[Test]
    public function fromArrayRespectsExplicitValues(): void
    {
        $env = Environment::load(null);
        $config = DatabaseConfig::fromArray([
            'default' => 'pgsql',
            'connections' => [
                'pgsql' => [
                    'driver' => 'pgsql',
                    'host' => 'db.example.com',
                    'port' => 5432,
                    'database' => 'app',
                    'username' => 'admin',
                    'password' => 'secret',
                ],
            ],
            'migrations' => [
                'table' => 'custom_migrations',
                'path' => 'custom/migrations',
            ],
        ], $env);

        self::assertSame('pgsql', $config->defaultConnection);
        self::assertSame('custom_migrations', $config->migrationsTable);
        self::assertSame('custom/migrations', $config->migrationsPath);

        $pgsql = $config->connection('pgsql');
        self::assertSame(Driver::PostgreSQL, $pgsql->driver);
        self::assertSame('app', $pgsql->database);
    }

    #[Test]
    public function connectionReturnsConfiguredConnection(): void
    {
        $env = Environment::load(null);
        $config = DatabaseConfig::fromArray([
            'connections' => [
                'sqlite' => [
                    'driver' => 'sqlite',
                    'database' => ':memory:',
                ],
            ],
        ], $env);

        $sqlite = $config->connection('sqlite');

        self::assertSame('sqlite', $sqlite->name);
        self::assertSame(Driver::SQLite, $sqlite->driver);
    }

    #[Test]
    public function connectionThrowsForUnknownName(): void
    {
        $env = Environment::load(null);
        $config = DatabaseConfig::fromArray([
            'connections' => [],
        ], $env);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Database connection "unknown" is not configured');

        $config->connection('unknown');
    }

    #[Test]
    public function fromArrayParsesMultipleConnections(): void
    {
        $env = Environment::load(null);
        $config = DatabaseConfig::fromArray([
            'connections' => [
                'mysql' => ['driver' => 'mysql', 'database' => 'app'],
                'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ],
        ], $env);

        self::assertCount(2, $config->connections);
        self::assertSame(Driver::MySQL, $config->connection('mysql')->driver);
        self::assertSame(Driver::SQLite, $config->connection('sqlite')->driver);
    }
}
