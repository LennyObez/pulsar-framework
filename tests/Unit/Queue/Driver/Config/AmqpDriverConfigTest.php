<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Driver\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Driver\Config\AmqpDriverConfig;

#[CoversClass(AmqpDriverConfig::class)]
final class AmqpDriverConfigTest extends TestCase
{
    #[Test]
    public function constructsWithDefaults(): void
    {
        $config = new AmqpDriverConfig();

        self::assertSame('127.0.0.1', $config->host);
        self::assertSame(5672, $config->port);
        self::assertSame('guest', $config->user);
        self::assertSame('guest', $config->password);
        self::assertSame('/', $config->vhost);
        self::assertSame('pulsar.queue', $config->exchange);
    }

    #[Test]
    public function constructsWithCustomValues(): void
    {
        $config = new AmqpDriverConfig(
            host: 'rabbitmq.internal',
            port: 5673,
            user: 'app_user',
            password: 'secret123',
            vhost: '/production',
            exchange: 'custom.exchange',
        );

        self::assertSame('rabbitmq.internal', $config->host);
        self::assertSame(5673, $config->port);
        self::assertSame('app_user', $config->user);
        self::assertSame('secret123', $config->password);
        self::assertSame('/production', $config->vhost);
        self::assertSame('custom.exchange', $config->exchange);
    }

    #[Test]
    public function fromArrayWithValidData(): void
    {
        $config = AmqpDriverConfig::fromArray([
            'host' => 'amqp-host',
            'port' => 5673,
            'user' => 'admin',
            'password' => 'pw',
            'vhost' => '/staging',
            'exchange' => 'test.exchange',
        ]);

        self::assertSame('amqp-host', $config->host);
        self::assertSame(5673, $config->port);
        self::assertSame('admin', $config->user);
        self::assertSame('pw', $config->password);
        self::assertSame('/staging', $config->vhost);
        self::assertSame('test.exchange', $config->exchange);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = AmqpDriverConfig::fromArray([]);

        self::assertSame('127.0.0.1', $config->host);
        self::assertSame(5672, $config->port);
        self::assertSame('guest', $config->user);
        self::assertSame('guest', $config->password);
        self::assertSame('/', $config->vhost);
        self::assertSame('pulsar.queue', $config->exchange);
    }

    #[Test]
    public function fromArrayWithInvalidTypeFallsBackToDefaults(): void
    {
        $config = AmqpDriverConfig::fromArray([
            'host' => 12345,
            'port' => 'not-a-number',
            'user' => false,
            'password' => [],
            'vhost' => null,
            'exchange' => 0,
        ]);

        self::assertSame('127.0.0.1', $config->host);
        self::assertSame(5672, $config->port);
        self::assertSame('guest', $config->user);
        self::assertSame('guest', $config->password);
        self::assertSame('/', $config->vhost);
        self::assertSame('pulsar.queue', $config->exchange);
    }

    #[Test]
    public function fromArrayWithPartialData(): void
    {
        $config = AmqpDriverConfig::fromArray([
            'host' => 'custom-host',
        ]);

        self::assertSame('custom-host', $config->host);
        self::assertSame(5672, $config->port);
        self::assertSame('guest', $config->user);
    }
}
