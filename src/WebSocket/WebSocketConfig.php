<?php

declare(strict_types=1);

namespace Pulsar\WebSocket;

use NoDiscard;
use Pulsar\Api\Api;

use function is_bool;
use function is_int;
use function is_string;

/**
 * Configuration for the WebSocket server.
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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            host: is_string($data['host'] ?? null) ? $data['host'] : '127.0.0.1',
            port: is_int($data['port'] ?? null) ? $data['port'] : 6001,
            maxConnections: is_int($data['max_connections'] ?? null) ? $data['max_connections'] : 10_000,
            maxPayloadSize: is_int($data['max_payload_size'] ?? null) ? $data['max_payload_size'] : 16_777_216,
            heartbeatIntervalSeconds: is_int($data['heartbeat_interval'] ?? null) ? $data['heartbeat_interval'] : 30,
            heartbeatTimeoutSeconds: is_int($data['heartbeat_timeout'] ?? null) ? $data['heartbeat_timeout'] : 60,
            maxChannelsPerConnection: is_int($data['max_channels_per_connection'] ?? null) ? $data['max_channels_per_connection'] : 100,
            enableCompression: is_bool($data['enable_compression'] ?? null) ? $data['enable_compression'] : false,
            path: is_string($data['path'] ?? null) ? $data['path'] : '/ws',
        );
    }
}
