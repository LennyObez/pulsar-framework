<?php

declare(strict_types=1);

namespace Pulsar\WebSocket\Routing;

use Psr\Container\ContainerInterface;
use Pulsar\Api\Api;
use Pulsar\WebSocket\Exception\WebSocketException;
use Pulsar\WebSocket\InboundMiddlewareInterface;
use Pulsar\WebSocket\MessageHandlerInterface;
use Pulsar\WebSocket\WebSocketCloseCode;
use Pulsar\WebSocket\WebSocketConnection;
use Pulsar\WebSocket\WebSocketFrame;
use Throwable;

use function count;
use function sprintf;

/**
 * Runs inbound frames through the middleware pipeline then dispatches to
 * the handler's `onMessage()`.
 *
 * This class is the single entry point the transport layer (FrankenPHP,
 * RoadRunner, Swoole adapter, etc.) uses after parsing a frame off the wire.
 *
 * Invariants (enforced by tests and by `tools/spec/MiddlewarePipeline.tla`
 * once written):
 *
 *  1. Control frames (Ping/Pong/Close) are never dispatched — the transport
 *     handles them itself before reaching here.
 *  2. Middleware runs in registration order; each middleware decides whether
 *     to call `$next` or short-circuit.
 *  3. Any exception thrown by middleware or handler routes to
 *     `MessageHandlerInterface::onError()`. The dispatcher does not swallow
 *     exceptions silently.
 *  4. If `onError` itself throws, the server logs and closes 1011.
 * @api
 */
#[Api(since: '1.0.0')]
final class InboundDispatcher
{
    public function __construct(
        private readonly RouteTable $routes,
        private readonly ContainerInterface $container,
    ) {}

    /**
     * Dispatch a frame along the route registered at `$path`.
     *
     * @throws WebSocketException if no route matches `$path`.
     */
    public function dispatch(string $path, WebSocketConnection $connection, WebSocketFrame $frame): void
    {
        $route = $this->routes->match($path);

        if ($route === null) {
            throw WebSocketException::unknownRoute($path);
        }

        $handler = $this->resolveHandler($route->handler);

        $terminal = static function (WebSocketConnection $c, WebSocketFrame $f) use ($handler): void {
            $handler->onMessage($c, $f);
        };
        $pipeline = $this->buildPipeline($route->middleware, $terminal);

        try {
            $pipeline($connection, $frame);
        } catch (Throwable $error) {
            $this->routeError($handler, $connection, $error);
        }
    }

    /**
     * Run the `onConnect` lifecycle for a newly accepted connection. Errors
     * here are routed to `onError` with the same rules as `dispatch()`.
     */
    public function dispatchConnect(string $path, WebSocketConnection $connection): void
    {
        $route = $this->routes->match($path);

        if ($route === null) {
            throw WebSocketException::unknownRoute($path);
        }

        $handler = $this->resolveHandler($route->handler);

        try {
            $handler->onConnect($connection);
        } catch (Throwable $error) {
            $this->routeError($handler, $connection, $error);
        }
    }

    /**
     * Run the `onDisconnect` lifecycle. Called by the transport after the
     * close frame round-trip or TCP half-close completes.
     */
    public function dispatchDisconnect(
        string $path,
        WebSocketConnection $connection,
        int $code,
        string $reason,
    ): void {
        $route = $this->routes->match($path);

        if ($route === null) {
            return; // connection already closed with no route — nothing to dispatch
        }

        $handler = $this->resolveHandler($route->handler);

        try {
            $handler->onDisconnect($connection, $code, $reason);
        } catch (Throwable $error) {
            $this->routeError($handler, $connection, $error);
        }
    }

    /**
     * Compose the middleware list into a single callable that eventually
     * yields to the terminal handler.
     *
     * @param list<class-string<InboundMiddlewareInterface>> $middleware
     * @param callable(WebSocketConnection, WebSocketFrame): void $terminal
     *
     * @return callable(WebSocketConnection, WebSocketFrame): void
     */
    private function buildPipeline(array $middleware, callable $terminal): callable
    {
        $next = $terminal;

        // Fold right so earlier middleware in the list wraps the later ones.
        for ($i = count($middleware) - 1; $i >= 0; $i--) {
            $instance = $this->resolveMiddleware($middleware[$i]);
            $current = $next;
            $next = static function (WebSocketConnection $c, WebSocketFrame $f) use ($instance, $current): void {
                $instance->process($c, $f, $current);
            };
        }

        return $next;
    }

    /**
     * @param class-string<MessageHandlerInterface> $class
     */
    private function resolveHandler(string $class): MessageHandlerInterface
    {
        $resolved = $this->container->get($class);

        if (!$resolved instanceof MessageHandlerInterface) {
            throw new WebSocketException(sprintf(
                'Handler "%s" must implement %s.',
                $class,
                MessageHandlerInterface::class,
            ));
        }

        return $resolved;
    }

    /**
     * @param class-string<InboundMiddlewareInterface> $class
     */
    private function resolveMiddleware(string $class): InboundMiddlewareInterface
    {
        $resolved = $this->container->get($class);

        if (!$resolved instanceof InboundMiddlewareInterface) {
            throw new WebSocketException(sprintf(
                'Middleware "%s" must implement %s.',
                $class,
                InboundMiddlewareInterface::class,
            ));
        }

        return $resolved;
    }

    private function routeError(
        MessageHandlerInterface $handler,
        WebSocketConnection $connection,
        Throwable $error,
    ): void {
        try {
            $handler->onError($connection, $error);
        } catch (Throwable) {
            // onError itself threw — default policy: close 1011. The transport
            // will surface the disconnect via dispatchDisconnect().
            if ($connection->isOpen()) {
                $connection->close(WebSocketCloseCode::InternalError->value, 'onError threw');
            }
        }
    }
}
