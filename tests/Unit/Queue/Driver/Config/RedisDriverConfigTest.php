<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Driver\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Driver\Config\RedisDriverConfig;

#[CoversClass(RedisDriverConfig::class)]
final class RedisDriverConfigTest extends TestCase
{
    #[Test]
    public function constructsWithDefaults(): void
    {
        $config = new RedisDriverConfig();

        self::assertSame('127.0.0.1', $config->host);
        self::assertSame(6379, $config->port);
        self::assertSame('', $config->password);
        self::assertSame(0, $config->database);
        self::assertSame('queue:', $config->prefix);
        self::assertSame(0.0, $config->timeout);
    }

    #[Test]
    public function constructsWithCustomValues(): void
    {
        $config = new RedisDriverConfig(
            host: 'redis.internal',
            port: 6380,
            password: 'redis-pw',
            database: 3,
            prefix: 'myapp:queue:',
            timeout: 5.0,
        );

        self::assertSame('redis.internal', $config->host);
        self::assertSame(6380, $config->port);
        self::assertSame('redis-pw', $config->password);
        self::assertSame(3, $config->database);
        self::assertSame('myapp:queue:', $config->prefix);
        self::assertSame(5.0, $config->timeout);
    }

    #[Test]
    public function fromArrayWithValidData(): void
    {
        $config = RedisDriverConfig::fromArray([
            'host' => 'redis-host',
            'port' => 6380,
            'password' => 'secret',
            'database' => 5,
            'prefix' => 'jobs:',
            'timeout' => 2.5,
        ]);

        self::assertSame('redis-host', $config->host);
        self::assertSame(6380, $config->port);
        self::assertSame('secret', $config->password);
        self::assertSame(5, $config->database);
        self::assertSame('jobs:', $config->prefix);
        self::assertSame(2.5, $config->timeout);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = RedisDriverConfig::fromArray([]);

        self::assertSame('127.0.0.1', $config->host);
        self::assertSame(6379, $config->port);
        self::assertSame('', $config->password);
        self::assertSame(0, $config->database);
        self::assertSame('queue:', $config->prefix);
        self::assertSame(0.0, $config->timeout);
    }

    #[Test]
    public function fromArrayWithInvalidTypeFallsBackToDefaults(): void
    {
        $config = RedisDriverConfig::fromArray([
            'host' => 42,
            'port' => 'abc',
            'password' => true,
            'database' => 'zero',
            'prefix' => [],
            'timeout' => 'slow',
        ]);

        self::assertSame('127.0.0.1', $config->host);
        self::assertSame(6379, $config->port);
        self::assertSame('', $config->password);
        self::assertSame(0, $config->database);
        self::assertSame('queue:', $config->prefix);
        self::assertSame(0.0, $config->timeout);
    }

    #[Test]
    public function fromArrayCastsIntegerTimeout(): void
    {
        $config = RedisDriverConfig::fromArray([
            'timeout' => 3,
        ]);

        self::assertSame(3.0, $config->timeout);
    }
}
