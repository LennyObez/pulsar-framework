<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Broadcasting\BroadcastAuthController;
use Pulsar\Broadcasting\BroadcastManager;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\BroadcastWiring;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\WebSocket\BroadcastManagerInterface as WebSocketBroadcastManagerInterface;
use Pulsar\WebSocket\ChannelAuthorizerInterface;

use function sys_get_temp_dir;

final class BroadcastWiringTest extends TestCase
{
    #[Test]
    public function bindsBroadcastManagerWhenTransportProvided(): void
    {
        $container = new Container();
        $container->instance(
            WebSocketBroadcastManagerInterface::class,
            $this->createStub(WebSocketBroadcastManagerInterface::class),
        );

        $this->wire($container, new Router());

        self::assertTrue($container->has(BroadcastManager::class), 'BroadcastManager is composed from the transport');
    }

    #[Test]
    public function registersAuthEndpointWhenAuthorizerProvided(): void
    {
        $container = new Container();
        $container->instance(
            WebSocketBroadcastManagerInterface::class,
            $this->createStub(WebSocketBroadcastManagerInterface::class),
        );
        $container->instance(
            ChannelAuthorizerInterface::class,
            $this->createStub(ChannelAuthorizerInterface::class),
        );
        $router = new Router();
        $before = $router->count();

        $this->wire($container, $router);

        self::assertTrue($container->has(BroadcastAuthController::class), 'auth controller bound');
        self::assertSame($before + 1, $router->count(), 'the /broadcasting/auth route is registered');
    }

    #[Test]
    public function isDormantWithoutTransport(): void
    {
        $container = new Container();
        $router = new Router();
        $before = $router->count();

        $this->wire($container, $router);

        self::assertFalse($container->has(BroadcastManager::class), 'no transport: broadcasting stays dormant');
        self::assertSame($before, $router->count());
    }

    #[Test]
    public function authEndpointSkippedWithoutAuthorizer(): void
    {
        $container = new Container();
        $container->instance(
            WebSocketBroadcastManagerInterface::class,
            $this->createStub(WebSocketBroadcastManagerInterface::class),
        );
        $router = new Router();
        $before = $router->count();

        $this->wire($container, $router);

        self::assertTrue($container->has(BroadcastManager::class));
        self::assertFalse($container->has(BroadcastAuthController::class), 'no authorizer: no auth endpoint');
        self::assertSame($before, $router->count());
    }

    private function wire(Container $container, Router $router): void
    {
        new BroadcastWiring()->wire(
            $container,
            new ConfigManager(sys_get_temp_dir()),
            new MiddlewarePipeline($container),
            new MiddlewareRegistry(),
            $router,
        );
    }
}
