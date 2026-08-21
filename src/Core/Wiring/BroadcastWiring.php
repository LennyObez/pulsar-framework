<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Broadcasting\BroadcastAuthController;
use Pulsar\Broadcasting\BroadcastManager;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\Wiring\Contract\DescribesWiring;
use Pulsar\Core\Wiring\Contract\OptionalBinding;
use Pulsar\Core\Wiring\Contract\WiringContract;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\RouteAccessRegistrar;
use Pulsar\Routing\Router;
use Pulsar\WebSocket\BroadcastManagerInterface as WebSocketBroadcastManagerInterface;
use Pulsar\WebSocket\ChannelAuthorizerInterface;

/**
 * Composes the broadcasting stack on top of an application-provided WebSocket
 * transport.
 *
 * Broadcasting is inherently infrastructure-driven: the cross-process WebSocket
 * transport and the channel-authorization policy are the application's (or its
 * deployment's) responsibility. Rather than bind a silently single-process
 * in-memory transport — which would drop cross-process broadcasts without a
 * trace — this wiring stays dormant until the application binds a real
 * {@see WebSocketBroadcastManagerInterface}, then:
 *  - binds {@see BroadcastManager} (the app-facing broadcast API), and
 *  - when a {@see ChannelAuthorizerInterface} is also bound, registers
 *    {@see BroadcastAuthController} and the POST /broadcasting/auth endpoint for
 *    private/presence channel authorization.
 */
#[Internal]
final readonly class BroadcastWiring implements ServiceWiringInterface, DescribesWiring
{
    public function describeWiring(): WiringContract
    {
        return new WiringContract(
            component: 'broadcasting',
            configFile: 'broadcasting.php',
            provides: [
                BroadcastManager::class,
                BroadcastAuthController::class,
            ],
            optional: [
                new OptionalBinding(
                    binding: WebSocketBroadcastManagerInterface::class,
                    feature: 'broadcasting (the BroadcastManager API and channel auth)',
                    fix: 'Bind a WebSocket BroadcastManagerInterface transport (a real WebSocket server adapter).',
                    security: false,
                ),
                new OptionalBinding(
                    binding: ChannelAuthorizerInterface::class,
                    feature: 'the private/presence channel auth endpoint (POST /broadcasting/auth)',
                    fix: 'Bind a ChannelAuthorizerInterface implementing your channel authorization policy.',
                    security: false,
                ),
            ],
        );
    }

    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        // Broadcasting requires an application-provided WebSocket transport.
        if (!$container->has(WebSocketBroadcastManagerInterface::class)) {
            return;
        }

        /** @var WebSocketBroadcastManagerInterface $transport */
        $transport = $container->get(WebSocketBroadcastManagerInterface::class);

        $logger = $container->has(LoggerInterface::class)
            ? $container->get(LoggerInterface::class)
            : null;
        /** @var LoggerInterface|null $logger */

        $container->instance(BroadcastManager::class, new BroadcastManager($transport, $logger));

        // Private/presence channel auth endpoint — only when the app supplies a policy.
        if ($container->has(ChannelAuthorizerInterface::class)) {
            /** @var ChannelAuthorizerInterface $authorizer */
            $authorizer = $container->get(ChannelAuthorizerInterface::class);
            $container->instance(BroadcastAuthController::class, new BroadcastAuthController($authorizer));

            // Authenticated, and until now it could not have been. The controller
            // reads the `identity` request attribute and refuses private and
            // presence channels to an unauthenticated caller -- but the only
            // middleware that resolves a real identity into that attribute is the
            // `auth` alias, and this registration never asked for it. The global
            // AuthenticationMiddleware writes AnonymousIdentity there, so the check
            // saw an anonymous identity on every request and no caller, however
            // logged in, could subscribe to a private channel. The alias is what
            // makes the controller's deny-by-default reachable at all.
            //
            // `_authenticated` rather than a named permission: which principals may
            // join a given channel is the application's ChannelAuthorizer decision,
            // taken per channel with the resolved identity. The route's own grant
            // is only "prove who you are first".
            $routes = new RouteAccessRegistrar($router, $middlewareRegistry, $logger);
            $routes->authenticated(
                [Method::POST],
                '/broadcasting/auth',
                [BroadcastAuthController::class, 'authenticate'],
                'pulsar.broadcasting.auth',
                [RouteAccessRegistrar::ANY_AUTHENTICATED],
                'Mints subscription tokens for private and presence channels, which are '
                    . 'identity-bound grants; the `auth` alias resolves the identity the '
                    . 'controller and the ChannelAuthorizer then authorize per channel.',
            );
        }
    }
}
