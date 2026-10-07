<?php

declare(strict_types=1);

namespace Pulsar\WebSocket;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

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
            host: Coerce::string($data['host'] ?? null, '127.0.0.1'),
            port: Coerce::int($data['port'] ?? null, 6001),
            maxConnections: Coerce::int($data['max_connections'] ?? null, 10_000),
            maxPayloadSize: Coerce::int($data['max_payload_size'] ?? null, 16_777_216),
            heartbeatIntervalSeconds: Coerce::int($data['heartbeat_interval'] ?? null, 30),
            heartbeatTimeoutSeconds: Coerce::int($data['heartbeat_timeout'] ?? null, 60),
            maxChannelsPerConnection: Coerce::int($data['max_channels_per_connection'] ?? null, 100),
            enableCompression: Coerce::strictBool($data['enable_compression'] ?? null),
            path: Coerce::string($data['path'] ?? null, '/ws'),
        );
    }
}
