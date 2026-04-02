<?php

declare(strict_types=1);

namespace Pulsar\WebSocket;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Configuration for the WebSocket server.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class WebSocketConfig
{
    public function __construct(
        public string $host = '127.0.0.1',
        public int $port = 6001,
        public int $maxConnections = 10_000,
        public int $maxPayloadSize = 16_777_216,
        public int $heartbeatIntervalSeconds = 30,
        public int $heartbeatTimeoutSeconds = 60,
        public int $maxChannelsPerConnection = 100,
        public bool $enableCompression = false,
        public string $path = '/ws',
    ) {}

    /**
     * @param array{
     *     host?: string,
     *     port?: int,
     *     max_connections?: int,
     *     max_payload_size?: int,
     *     heartbeat_interval?: int,
     *     heartbeat_timeout?: int,
     *     max_channels_per_connection?: int,
     *     enable_compression?: bool,
     *     path?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            host: $data['host'] ?? '127.0.0.1',
            port: $data['port'] ?? 6001,
            maxConnections: $data['max_connections'] ?? 10_000,
            maxPayloadSize: $data['max_payload_size'] ?? 16_777_216,
            heartbeatIntervalSeconds: $data['heartbeat_interval'] ?? 30,
            heartbeatTimeoutSeconds: $data['heartbeat_timeout'] ?? 60,
            maxChannelsPerConnection: $data['max_channels_per_connection'] ?? 100,
            enableCompression: $data['enable_compression'] ?? false,
            path: $data['path'] ?? '/ws',
        );
    }
}
