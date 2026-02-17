<?php

declare(strict_types=1);

namespace Pulsar\WebSocket;

use Pulsar\Api\Api;

/**
 * Contract for broadcasting events to WebSocket channels.
 */
#[Api(since: '1.0.0')]
interface BroadcastManagerInterface
{
    /**
     * Broadcast an event to a channel.
     *
     * @param string $channel Channel name
     * @param string $event Event name
     * @param array<string, mixed> $data Event payload
     */
    public function broadcast(string $channel, string $event, array $data): void;

    /**
     * Broadcast an event to a channel, excluding specific connections.
     *
     * @param string $channel Channel name
     * @param string $event Event name
     * @param array<string, mixed> $data Event payload
     * @param list<string> $excludeConnectionIds Connection IDs to exclude
     */
    public function broadcastExcept(string $channel, string $event, array $data, array $excludeConnectionIds): void;

    /**
     * Send a message to a specific connection.
     *
     * @param string $connectionId Target connection ID
     * @param string $event Event name
     * @param array<string, mixed> $data Event payload
     */
    public function sendTo(string $connectionId, string $event, array $data): void;
}
