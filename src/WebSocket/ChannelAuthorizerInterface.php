<?php

declare(strict_types=1);

namespace Pulsar\WebSocket;

use Pulsar\Api\Api;

/**
 * Contract for authorizing access to private and presence channels.
 * @api
 */
#[Api(since: '1.0.0')]
interface ChannelAuthorizerInterface
{
    /**
     * Authorize a connection for a private channel.
     *
     * @param string $channel Channel name
     * @param WebSocketConnection $connection The connection requesting access
     * @return bool Whether access is granted
     */
    public function authorizePrivate(string $channel, WebSocketConnection $connection): bool;

    /**
     * Authorize a connection for a presence channel and return user info.
     *
     * @param string $channel Channel name
     * @param WebSocketConnection $connection The connection requesting access
     * @return array<string, mixed>|null User info if authorized, null if denied
     */
    public function authorizePresence(string $channel, WebSocketConnection $connection): ?array;
}
