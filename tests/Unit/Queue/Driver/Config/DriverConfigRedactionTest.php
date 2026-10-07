<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Driver\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Driver\Config\AmqpDriverConfig;
use Pulsar\Queue\Driver\Config\RedisDriverConfig;
use Pulsar\Queue\Driver\Config\SqsDriverConfig;

/**
 * Verifies that driver configuration DTOs redact secret fields from debug
 * output so credentials cannot leak through var_dump()/debug dumps.
 */
#[CoversClass(SqsDriverConfig::class)]
#[CoversClass(RedisDriverConfig::class)]
#[CoversClass(AmqpDriverConfig::class)]
final class DriverConfigRedactionTest extends TestCase
{
    #[Test]
    public function sqsConfigRedactsKeyAndSecret(): void
    {
        $config = new SqsDriverConfig(
            region: 'eu-west-1',
            key: 'AKIAEXAMPLE',
            secret: 'super-secret-value',
            prefix: 'app-',
        );

        $debug = $config->__debugInfo();

        self::assertSame('eu-west-1', $debug['region']);
        self::assertSame('app-', $debug['prefix']);
        self::assertSame('[REDACTED]', $debug['key']);
        self::assertSame('[REDACTED]', $debug['secret']);
    }

    #[Test]
    public function sqsConfigDoesNotMaskEmptyCredentials(): void
    {
        $config = new SqsDriverConfig();

        $debug = $config->__debugInfo();

        self::assertSame('', $debug['key']);
        self::assertSame('', $debug['secret']);
    }

    #[Test]
    public function redisConfigRedactsPassword(): void
    {
        $config = new RedisDriverConfig(
            host: 'redis.internal',
            port: 6380,
            password: 'redis-secret',
            database: 2,
            prefix: 'jobs:',
            timeout: 1.5,
        );

        $debug = $config->__debugInfo();

        self::assertSame('redis.internal', $debug['host']);
        self::assertSame(6380, $debug['port']);
        self::assertSame(2, $debug['database']);
        self::assertSame('jobs:', $debug['prefix']);
        self::assertSame(1.5, $debug['timeout']);
        self::assertSame('[REDACTED]', $debug['password']);
    }

    #[Test]
    public function redisConfigDoesNotMaskEmptyPassword(): void
    {
        $config = new RedisDriverConfig();

        self::assertSame('', $config->__debugInfo()['password']);
    }

    #[Test]
    public function amqpConfigRedactsPasswordButKeepsUser(): void
    {
        $config = new AmqpDriverConfig(
            host: 'amqp.internal',
            port: 5673,
            user: 'service-account',
            password: 'broker-secret',
            vhost: '/app',
            exchange: 'app.queue',
        );

        $debug = $config->__debugInfo();

        self::assertSame('amqp.internal', $debug['host']);
        self::assertSame(5673, $debug['port']);
        self::assertSame('service-account', $debug['user']);
        self::assertSame('/app', $debug['vhost']);
        self::assertSame('app.queue', $debug['exchange']);
        self::assertSame('[REDACTED]', $debug['password']);
    }
}
