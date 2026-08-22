<?php

declare(strict_types=1);

namespace Pulsar\WebSocket;

use Pulsar\Api\Api;

/**
 * PSR-15-style middleware for inbound WebSocket frames.
 *
 * The inbound pipeline mirrors the HTTP middleware pipeline: each middleware
 * receives the frame plus the connection and may pass through to the next
 * handler, transform the frame, short-circuit (e.g. reject malformed payloads,
 * rate-limit), or throw (server routes to `MessageHandlerInterface::onError`).
 *
 * Composition laws (verified by `tests/Property/InboundPipelineTest` — see
 * future `tools/spec/MiddlewarePipeline.tla` for the formal proof):
 *
 *  - Identity: a middleware that calls `$next($connection, $frame)` unchanged
 *    must compose identically to no middleware at all.
 *  - Associativity: the composition `a . (b . c)` equals `(a . b) . c`.
 *
 * Example:
 *
 * ```php
 * final class AuthenticateMiddleware implements InboundMiddlewareInterface
 * {
 *     public function process(
 *         WebSocketConnection $connection,
 *         WebSocketFrame $frame,
 *         callable $next,
 *     ): void {
 *         if (!$connection->isAuthenticated()) {
 *             $connection->close(WebSocketCloseCode::PolicyViolation->value, 'unauthenticated');
 *             return;
 *         }
 *         $next($connection, $frame);
 *     }
 * }
 * ```
 * @api
 */
#[Api(since: '1.0.0')]
interface InboundMiddlewareInterface
{
    /**
     * Process an inbound frame.
     *
     * `$next` is a callable with signature
     * `(WebSocketConnection $connection, WebSocketFrame $frame): void` that
     * yields to the next middleware or, eventually, to the handler's
     * `onMessage()`. Middleware may call `$next` zero, one, or many times
     * (fan-out is legal but uncommon).
     *
     * @param callable(WebSocketConnection, WebSocketFrame): void $next
     */
    public function process(
        WebSocketConnection $connection,
        WebSocketFrame $frame,
        callable $next,
    ): void;
}
