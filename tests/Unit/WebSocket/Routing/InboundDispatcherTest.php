<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\WebSocket\Routing;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Pulsar\WebSocket\MessageHandlerInterface;
use Pulsar\WebSocket\Routing\InboundDispatcher;
use Pulsar\WebSocket\Routing\RouteTable;
use Pulsar\WebSocket\WebSocketConnection;
use Pulsar\WebSocket\WebSocketFrame;
use RuntimeException;
use Throwable;

#[CoversClass(InboundDispatcher::class)]
final class InboundDispatcherTest extends TestCase
{
    #[Test]
    public function disconnectExceptionRoutesToOnError(): void
    {
        $handler = new RecordingHandler(throwOnDisconnect: true);
        $routes = new RouteTable();
        $routes->register('/ws', RecordingHandler::class);

        $dispatcher = new InboundDispatcher(
            $routes,
            new SingleHandlerContainer(RecordingHandler::class, $handler),
        );

        $connection = new WebSocketConnection('conn-1', 0.0);

        // Must not propagate the handler's exception to the transport layer.
        $dispatcher->dispatchDisconnect('/ws', $connection, 1000, 'bye');

        self::assertTrue($handler->disconnectCalled);
        self::assertNotNull($handler->lastError);
        self::assertSame('boom from onDisconnect', $handler->lastError->getMessage());
    }

    #[Test]
    public function disconnectWithoutErrorDoesNotInvokeOnError(): void
    {
        $handler = new RecordingHandler(throwOnDisconnect: false);
        $routes = new RouteTable();
        $routes->register('/ws', RecordingHandler::class);

        $dispatcher = new InboundDispatcher(
            $routes,
            new SingleHandlerContainer(RecordingHandler::class, $handler),
        );

        $connection = new WebSocketConnection('conn-1', 0.0);

        $dispatcher->dispatchDisconnect('/ws', $connection, 1000, 'bye');

        self::assertTrue($handler->disconnectCalled);
        self::assertNull($handler->lastError);
    }

    #[Test]
    public function disconnectWithNoMatchingRouteIsNoop(): void
    {
        $handler = new RecordingHandler(throwOnDisconnect: true);
        $routes = new RouteTable();

        $dispatcher = new InboundDispatcher(
            $routes,
            new SingleHandlerContainer(RecordingHandler::class, $handler),
        );

        $connection = new WebSocketConnection('conn-1', 0.0);

        $dispatcher->dispatchDisconnect('/unknown', $connection, 1000, 'bye');

        self::assertFalse($handler->disconnectCalled);
    }
}

/**
 * Records lifecycle calls; optionally throws from `onDisconnect` to prove that
 * the dispatcher routes the failure through `onError`.
 */
final class RecordingHandler implements MessageHandlerInterface
{
    public bool $disconnectCalled = false;

    public ?Throwable $lastError = null;

    public function __construct(private readonly bool $throwOnDisconnect) {}

    #[Override]
    public function onConnect(WebSocketConnection $connection): void {}

    #[Override]
    public function onMessage(WebSocketConnection $connection, WebSocketFrame $frame): void {}

    #[Override]
    public function onDisconnect(WebSocketConnection $connection, int $code, string $reason): void
    {
        $this->disconnectCalled = true;

        if ($this->throwOnDisconnect) {
            throw new RuntimeException('boom from onDisconnect');
        }
    }

    #[Override]
    public function onError(WebSocketConnection $connection, Throwable $error): void
    {
        $this->lastError = $error;
    }
}

/**
 * Minimal PSR-11 container that resolves a single handler class-string to a
 * pre-built instance.
 */
final class SingleHandlerContainer implements ContainerInterface
{
    public function __construct(
        private readonly string $id,
        private readonly MessageHandlerInterface $handler,
    ) {}

    #[Override]
    public function get(string $id): mixed
    {
        if ($id === $this->id) {
            return $this->handler;
        }

        throw new RuntimeException('Unexpected service id: ' . $id);
    }

    #[Override]
    public function has(string $id): bool
    {
        return $id === $this->id;
    }
}
