<?php

declare(strict_types=1);

namespace Pulsar\WebSocket\Internal;

use Pulsar\Api\Internal;
use Pulsar\WebSocket\FrameSinkInterface;
use Pulsar\WebSocket\WebSocketConnection;
use Pulsar\WebSocket\WebSocketFrame;

/**
 * No-op sink used for connection objects that exist purely for authorization
 * or channel-lookup contexts where no actual transport is attached (e.g.
 * `Broadcasting\BroadcastAuthController` constructs an ephemeral connection
 * to run channel authorizers without a live wire).
 *
 * All writes are discarded. `isOpen()` returns `false`.
 */
#[Internal]
final class NullFrameSink implements FrameSinkInterface
{
    public function push(WebSocketConnection $connection, WebSocketFrame $frame): void
    {
        // Discard.
    }

    public function close(WebSocketConnection $connection, int $code, string $reason): void
    {
        // Discard.
    }

    public function isOpen(WebSocketConnection $connection): bool
    {
        return false;
    }
}
