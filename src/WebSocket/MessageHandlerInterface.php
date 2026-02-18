<?php

declare(strict_types=1);

namespace Pulsar\WebSocket;

use Pulsar\Api\Api;
use Throwable;

/**
 * Contract for handling the lifecycle of an inbound WebSocket message pipeline.
 *
 * Implementations are invoked by the WebSocket server once handshake has succeeded
 * and the connection is accepted for a given route.
 *
 * Lifecycle guarantees:
 *  1. `onConnect` is called exactly once per accepted connection, **before**
 *     any `onMessage` call.
 *  2. `onMessage` is called for every inbound non-control frame the server
 *     dispatches through the inbound middleware pipeline. Control frames
 *     (Ping/Pong/Close) are handled by the server itself and never surface here.
 *  3. `onDisconnect` is called exactly once per connection. After it returns,
 *     no further callback on the same connection is invoked.
 *  4. `onError` is called for any uncaught exception thrown by an inbound
 *     middleware or by `onMessage` / `onConnect`. The server decides whether
 *     to close the connection (typical close code 1011 Internal Error).
 *
 * Implementations must be stateless across connections — per-connection state
 * belongs on the `WebSocketConnection::setMeta()` bag.
 */
#[Api(since: '1.0.0')]
interface MessageHandlerInterface
{
    /**
     * Called exactly once per accepted connection after the handshake and the
     * inbound middleware pipeline have authorised the connection.
     *
     * @throws Throwable the server catches and routes to `onError`
     */
    public function onConnect(WebSocketConnection $connection): void;

    /**
     * Called for each inbound non-control frame reaching the handler after
     * traversing the inbound middleware pipeline.
     *
     * The frame payload is delivered verbatim. The handler is responsible for
     * decoding (JSON, msgpack, binary protocols). The middleware pipeline is
     * the right place to put protocol framing concerns.
     *
     * @throws Throwable the server catches and routes to `onError`
     */
    public function onMessage(WebSocketConnection $connection, WebSocketFrame $frame): void;

    /**
     * Called exactly once when the connection closes, from either side.
     *
     * `$code` matches RFC 6455 Section 7.4.1 (e.g. 1000 Normal, 1001 Going Away,
     * 1006 Abnormal Closure, 1011 Internal Error). `$reason` may be empty.
     *
     * After this method returns, the connection is removed from the server's
     * active set and any pending outbound frames are discarded.
     */
    public function onDisconnect(WebSocketConnection $connection, int $code, string $reason): void;

    /**
     * Called when an uncaught exception bubbled up from the inbound middleware
     * pipeline, `onConnect`, or `onMessage`.
     *
     * The server's default policy is to log the exception, send a close frame
     * with code 1011 Internal Error, and invoke `onDisconnect` next. Handlers
     * may suppress the close by recovering here.
     */
    public function onError(WebSocketConnection $connection, Throwable $error): void;
}
