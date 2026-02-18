<?php

declare(strict_types=1);

namespace Pulsar\WebSocket\Exception;

use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Base exception for the WebSocket subsystem.
 *
 * Thrown for violations of framework invariants (identity rebinding, route
 * conflicts, handler registration mistakes). Protocol-level errors that the
 * spec instructs to close with a numeric code are represented by returning
 * the close code directly, not by throwing.
 */
#[Api(since: '1.0.0')]
class WebSocketException extends RuntimeException
{
    public static function identityAlreadyBound(
        string $connectionId,
        string $currentUserId,
        string $attemptedUserId,
    ): self {
        return new self(sprintf(
            'Connection %s is already bound to user "%s"; cannot rebind to "%s".',
            $connectionId,
            $currentUserId,
            $attemptedUserId,
        ));
    }

    public static function duplicateRoute(string $path): self
    {
        return new self(sprintf(
            'A WebSocket handler is already registered for path "%s".',
            $path,
        ));
    }

    public static function unknownRoute(string $path): self
    {
        return new self(sprintf(
            'No WebSocket handler is registered for path "%s".',
            $path,
        ));
    }

    public static function middlewareMustReturnVoid(string $middleware): self
    {
        return new self(sprintf(
            'Inbound middleware "%s" must have void return type.',
            $middleware,
        ));
    }
}
