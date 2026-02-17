<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\WebSocket;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\WebSocket\WebSocketConfig;

#[CoversClass(WebSocketConfig::class)]
final class WebSocketConfigTest extends TestCase
{
    #[Test]
    public function defaults(): void
    {
        $config = new WebSocketConfig();

        self::assertSame('127.0.0.1', $config->host);
        self::assertSame(6001, $config->port);
        self::assertSame(10_000, $config->maxConnections);
        self::assertSame(16_777_216, $config->maxPayloadSize);
        self::assertSame(30, $config->heartbeatIntervalSeconds);
        self::assertSame(60, $config->heartbeatTimeoutSeconds);
        self::assertSame(100, $config->maxChannelsPerConnection);
        self::assertFalse($config->enableCompression);
        self::assertSame('/ws', $config->path);
    }

    #[Test]
    public function fromArrayWithAllFields(): void
    {
        $config = WebSocketConfig::fromArray([
            'host' => '0.0.0.0',
            'port' => 8443,
            'max_connections' => 5000,
            'max_payload_size' => 1_048_576,
            'heartbeat_interval' => 15,
            'heartbeat_timeout' => 45,
            'max_channels_per_connection' => 50,
            'enable_compression' => true,
            'path' => '/realtime',
        ]);

        self::assertSame('0.0.0.0', $config->host);
        self::assertSame(8443, $config->port);
        self::assertSame(5000, $config->maxConnections);
        self::assertSame(1_048_576, $config->maxPayloadSize);
        self::assertSame(15, $config->heartbeatIntervalSeconds);
        self::assertSame(45, $config->heartbeatTimeoutSeconds);
        self::assertSame(50, $config->maxChannelsPerConnection);
        self::assertTrue($config->enableCompression);
        self::assertSame('/realtime', $config->path);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = WebSocketConfig::fromArray([]);

        self::assertSame('127.0.0.1', $config->host);
        self::assertSame(6001, $config->port);
    }

    #[Test]
    public function fromArrayIgnoresInvalidTypes(): void
    {
        $config = WebSocketConfig::fromArray([
            'host' => 42,
            'port' => 'not-a-port',
            'max_connections' => 'many',
            'enable_compression' => 'yes',
        ]);

        self::assertSame('127.0.0.1', $config->host);
        self::assertSame(6001, $config->port);
        self::assertSame(10_000, $config->maxConnections);
        self::assertFalse($config->enableCompression);
    }
}
