<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConnectionConfig;
use Pulsar\Config\Environment;
use Pulsar\Database\Driver;

#[CoversClass(ConnectionConfig::class)]
final class ConnectionConfigTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $savedEnvVars = [];

    private const array DB_ENV_KEYS = ['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];

    protected function setUp(): void
    {
        foreach (self::DB_ENV_KEYS as $key) {
            $this->savedEnvVars[$key] = getenv($key);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::DB_ENV_KEYS as $key) {
            $saved = $this->savedEnvVars[$key];
            if ($saved === false) {
                putenv($key);
            } else {
                putenv("{$key}={$saved}");
            }
        }
    }

    #[Test]
    public function fromArrayCreatesConfigWithDefaults(): void
    {
        $env = Environment::load(null);
        $config = ConnectionConfig::fromArray('test', [
            'driver' => 'mysql',
        ], $env);

        self::assertSame('test', $config->name);
        self::assertSame(Driver::MySQL, $config->driver);
        self::assertSame('127.0.0.1', $config->host);
        self::assertSame(3306, $config->port);
        self::assertSame('', $config->database);
        self::assertSame('', $config->username);
        self::assertSame('', $config->password);
        self::assertSame('utf8mb4', $config->charset);
        self::assertSame('utf8mb4_unicode_ci', $config->collation);
        self::assertSame([], $config->options);
    }

    #[Test]
    public function fromArrayParsesExplicitValues(): void
    {
        $env = Environment::load(null);
        $config = ConnectionConfig::fromArray('primary', [
            'driver' => 'pgsql',
            'host' => 'db.example.com',
            'port' => 5433,
            'database' => 'app_db',
            'username' => 'admin',
            'password' => 'secret',
            'charset' => 'utf8',
            'collation' => '',
        ], $env);

        self::assertSame('primary', $config->name);
        self::assertSame(Driver::PostgreSQL, $config->driver);
        self::assertSame('db.example.com', $config->host);
        self::assertSame(5433, $config->port);
        self::assertSame('app_db', $config->database);
        self::assertSame('admin', $config->username);
        self::assertSame('secret', $config->password);
        self::assertSame('utf8', $config->charset);
    }

    #[Test]
    public function fromArrayUsesSqliteDriverCorrectly(): void
    {
        $env = Environment::load(null);
        $config = ConnectionConfig::fromArray('local', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ], $env);

        self::assertSame(Driver::SQLite, $config->driver);
        self::assertSame(0, $config->port);
    }

    #[Test]
    public function fromArrayUsesDriverDefaultPort(): void
    {
        $env = Environment::load(null);
        $config = ConnectionConfig::fromArray('pg', [
            'driver' => 'pgsql',
        ], $env);

        self::assertSame(5432, $config->port);
    }

    #[Test]
    public function fromArrayPassesThroughOptions(): void
    {
        $env = Environment::load(null);
        $config = ConnectionConfig::fromArray('test', [
            'driver' => 'mysql',
            'options' => ['timeout' => 5],
        ], $env);

        self::assertSame(['timeout' => 5], $config->options);
    }
}
