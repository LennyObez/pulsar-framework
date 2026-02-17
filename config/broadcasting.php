<?php

declare(strict_types=1);

/**
 * WebSocket and broadcasting configuration.
 *
 * @see \Pulsar\WebSocket\WebSocketConfig
 */
return [
    // WebSocket server bind address
    'host' => '127.0.0.1',

    // WebSocket server port
    'port' => 6001,

    // Maximum concurrent connections
    'max_connections' => 10_000,

    // Maximum payload size in bytes (16 MiB)
    'max_payload_size' => 16_777_216,

    // Heartbeat interval in seconds (ping/pong)
    'heartbeat_interval' => 30,

    // Heartbeat timeout in seconds (close if no pong)
    'heartbeat_timeout' => 60,

    // Maximum channels per connection
    'max_channels_per_connection' => 100,

    // Enable per-message compression (permessage-deflate)
    'enable_compression' => false,

    // WebSocket endpoint path
    'path' => '/ws',
];
