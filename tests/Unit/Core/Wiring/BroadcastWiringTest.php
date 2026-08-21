<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Middleware\AuthorizationMiddleware;
use Pulsar\Broadcasting\BroadcastAuthController;
use Pulsar\Broadcasting\BroadcastManager;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\BroadcastWiring;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\RouteAccess;
use Pulsar\Routing\RouteAccessRegistrar;
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

    /**
     * The endpoint's guard is the `auth` alias, and without it nothing enforces
     * the permission the route declares. Registering the route anyway would
     * publish an identity-bound grant with its authorization sitting inertly in
     * the attributes, so the registrar declines and the endpoint is simply
     * absent -- 404 rather than a guard that cannot run.
     */
    #[Test]
    public function authEndpointIsNotRegisteredWhenTheAuthAliasIsMissing(): void
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

        $this->wire($container, $router, new MiddlewareRegistry());

        self::assertSame(0, $router->count(), 'no guard available: no route');
    }

    /**
     * And when the alias is there, the route carries both halves: the middleware
     * that resolves an identity and a permission for AuthorizationMiddleware to
     * check, since it default-denies an empty list.
     */
    #[Test]
    public function theAuthEndpointDeclaresBothItsGuardAndItsPermission(): void
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

        $this->wire($container, $router);

        $route = $router->namedRoutes['pulsar.broadcasting.auth'] ?? null;

        self::assertNotNull($route);
        self::assertSame(RouteAccess::Authenticated, RouteAccess::of($route));
        self::assertContains(RouteAccessRegistrar::AUTH_ALIAS, $route->middleware);
        self::assertSame(
            [RouteAccessRegistrar::ANY_AUTHENTICATED],
            $route->attributes['permissions'] ?? null,
        );
    }

    private function wire(Container $container, Router $router, ?MiddlewareRegistry $registry = null): void
    {
        if ($registry === null) {
            $registry = new MiddlewareRegistry();
            $registry->alias(RouteAccessRegistrar::AUTH_ALIAS, AuthorizationMiddleware::class);
        }

        new BroadcastWiring()->wire(
            $container,
            new ConfigManager(sys_get_temp_dir()),
            new MiddlewarePipeline($container),
            $registry,
            $router,
        );
    }
}
