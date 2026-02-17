<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\Dev;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\Dev\DevConfig;

#[CoversClass(DevConfig::class)]
final class DevConfigTest extends TestCase
{
    #[Test]
    public function defaults(): void
    {
        $config = new DevConfig();

        self::assertSame('8.5', $config->phpVersion);
        self::assertSame('pgsql', $config->database);
        self::assertTrue($config->redis);
        self::assertTrue($config->mailpit);
        self::assertSame('built-in', $config->serverDriver);
        self::assertSame(8080, $config->appPort);
        self::assertSame(5432, $config->dbPort);
        self::assertSame(6379, $config->redisPort);
        self::assertSame(1025, $config->mailpitSmtpPort);
        self::assertSame(8025, $config->mailpitWebPort);
        self::assertSame(512, $config->memoryLimit);
        self::assertTrue($config->xdebug);
        self::assertSame([], $config->phpExtensions);
        self::assertSame('pulsar', $config->projectName);
    }

    #[Test]
    public function fromArrayWithAllFields(): void
    {
        $config = DevConfig::fromArray([
            'php_version' => '8.4',
            'database' => 'mysql',
            'redis' => false,
            'mailpit' => false,
            'server_driver' => 'frankenphp',
            'app_port' => 9090,
            'db_port' => 3307,
            'redis_port' => 6380,
            'mailpit_smtp_port' => 2025,
            'mailpit_web_port' => 9025,
            'memory_limit' => 1024,
            'xdebug' => false,
            'php_extensions' => ['imagick'],
            'project_name' => 'myapp',
        ]);

        self::assertSame('8.4', $config->phpVersion);
        self::assertSame('mysql', $config->database);
        self::assertFalse($config->redis);
        self::assertFalse($config->mailpit);
        self::assertSame('frankenphp', $config->serverDriver);
        self::assertSame(9090, $config->appPort);
        self::assertSame(3307, $config->dbPort);
        self::assertSame(6380, $config->redisPort);
        self::assertSame(2025, $config->mailpitSmtpPort);
        self::assertSame(9025, $config->mailpitWebPort);
        self::assertSame(1024, $config->memoryLimit);
        self::assertFalse($config->xdebug);
        self::assertSame(['imagick'], $config->phpExtensions);
        self::assertSame('myapp', $config->projectName);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = DevConfig::fromArray([]);

        self::assertSame('8.5', $config->phpVersion);
        self::assertSame('pgsql', $config->database);
        self::assertTrue($config->redis);
    }

    #[Test]
    #[DataProvider('invalidDatabaseProvider')]
    public function fromArrayRejectsInvalidDatabase(mixed $value): void
    {
        $config = DevConfig::fromArray(['database' => $value]);

        self::assertSame('pgsql', $config->database);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidDatabaseProvider(): iterable
    {
        yield 'mongodb' => ['mongodb'];
        yield 'integer' => [42];
        yield 'null' => [null];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('invalidServerProvider')]
    public function fromArrayRejectsInvalidServer(mixed $value): void
    {
        $config = DevConfig::fromArray(['server_driver' => $value]);

        self::assertSame('built-in', $config->serverDriver);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidServerProvider(): iterable
    {
        yield 'nginx' => ['nginx'];
        yield 'integer' => [42];
        yield 'null' => [null];
    }

    #[Test]
    public function fromArrayClampsInvalidPorts(): void
    {
        $config = DevConfig::fromArray([
            'app_port' => 0,
            'db_port' => -1,
            'redis_port' => 70000,
        ]);

        self::assertSame(8080, $config->appPort);
        self::assertSame(5432, $config->dbPort);
        self::assertSame(6379, $config->redisPort);
    }

    #[Test]
    public function fromArrayFiltersNonStringExtensions(): void
    {
        $config = DevConfig::fromArray([
            'php_extensions' => ['imagick', 42, null, 'apcu'],
        ]);

        self::assertSame(['imagick', 'apcu'], $config->phpExtensions);
    }

    #[Test]
    public function defaultDbPortForPostgres(): void
    {
        $config = new DevConfig(database: 'pgsql');

        self::assertSame(5432, $config->defaultDbPort());
    }

    #[Test]
    public function defaultDbPortForMysql(): void
    {
        $config = new DevConfig(database: 'mysql');

        self::assertSame(3306, $config->defaultDbPort());
    }

    #[Test]
    public function defaultDbPortForSqlite(): void
    {
        $config = new DevConfig(database: 'sqlite');

        self::assertSame(0, $config->defaultDbPort());
    }

    #[Test]
    public function requiresDatabaseContainerForPostgres(): void
    {
        $config = new DevConfig(database: 'pgsql');

        self::assertTrue($config->requiresDatabaseContainer());
    }

    #[Test]
    public function requiresDatabaseContainerForMysql(): void
    {
        $config = new DevConfig(database: 'mysql');

        self::assertTrue($config->requiresDatabaseContainer());
    }

    #[Test]
    public function doesNotRequireDatabaseContainerForSqlite(): void
    {
        $config = new DevConfig(database: 'sqlite');

        self::assertFalse($config->requiresDatabaseContainer());
    }
}
