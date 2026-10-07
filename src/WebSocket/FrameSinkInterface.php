<?php

declare(strict_types=1);

namespace Pulsar\WebSocket;

use Pulsar\Api\Api;

/**
 * Transport-agnostic sink for pushing frames back onto a connection.
 *
 * A `FrameSinkInterface` is what `WebSocketConnection` uses to write to the
 * underlying transport (FrankenPHP, RoadRunner, ReactPHP, Swoole, OpenSwoole,
 * Amp, raw FPM with WebSocket upgrade). Implementations are server-specific
 * and are **not** part of the `#[Api]` surface — they are infrastructure.
 *
 * The test harness ships `Testing\FakeFrameSink` that records frames in
 * memory for assertions.
 * @api
 */
#[Api(since: '1.0.0')]
interface FrameSinkInterface
{
    /**
     * Write a frame to the connection. Implementations may buffer; they must
     * flush latest by the next tick of the event loop.
     *
     * Throws no exception on a closed connection — writes after close are
     * silently discarded. Callers must check `isOpen()` first if they need
     * to react.
     */
    public function push(WebSocketConnection $connection, WebSocketFrame $frame): void;

    /**
     * Initiate an orderly close with RFC 6455 code and reason. After this
     * call, `isOpen()` returns `false` and subsequent `push()` calls are
     * discarded. The server still invokes `MessageHandlerInterface::onDisconnect`
     * once the TCP half-close completes.
     */
    public function close(WebSocketConnection $connection, int $code, string $reason): void;

    /**
     * Whether the underlying transport still accepts writes for this connection.
     */
    public function isOpen(WebSocketConnection $connection): bool;
}
