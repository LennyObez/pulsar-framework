<?php

declare(strict_types=1);

namespace Pulsar\WebSocket\Routing;

use Pulsar\Api\Api;
use Pulsar\WebSocket\InboundMiddlewareInterface;
use Pulsar\WebSocket\MessageHandlerInterface;

/**
 * Immutable association between a URL path and its inbound handler + middleware.
 *
 * A route is constructed by `RouteTable::register()` and dispatched by
 * `InboundDispatcher::dispatch()`. Handlers are instantiated via the DI
 * container — routes hold their class-strings, not the resolved objects,
 * so that per-request services (logger with correlation ID, etc.) bind
 * correctly.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Route
{
    /**
     * @param class-string<MessageHandlerInterface> $handler
     * @param list<class-string<InboundMiddlewareInterface>> $middleware
     */
    public function __construct(
        public string $path,
        public string $handler,
        public array $middleware = [],
    ) {}
}
