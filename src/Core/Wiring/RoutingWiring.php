<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\RoutingConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Middleware\CanonicalPathMiddleware;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;

/**
 * Wires request-URL canonicalization.
 *
 * Runs early in the boot order (right after ConfigWiring, before I18nWiring) so
 * the canonicalization middleware is piped OUTERMOST — it observes the full
 * request path, including any locale prefix, before LocalePrefixMiddleware
 * rewrites it. That lets `/nl//coaching` redirect to `/nl/coaching` (prefix
 * kept, double slash collapsed) instead of leaking a `//coaching` spelling
 * downstream.
 *
 * When no `config/routing.php` is present the repository has no RoutingConfig,
 * so the default (canonicalization OFF) applies and nothing is piped — the
 * router keeps its historical forgiving behaviour, preserving backwards
 * compatibility.
 */
#[Internal]
final readonly class RoutingWiring implements ServiceWiringInterface
{
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $repository = $configManager->repository();

        $config = $repository->has(RoutingConfig::class)
            ? $repository->get(RoutingConfig::class)
            : new RoutingConfig();

        /** @var RoutingConfig $config */
        $container->instance(RoutingConfig::class, $config);

        if ($config->redirectToCanonicalPath) {
            $middleware->pipe(new CanonicalPathMiddleware());
        }
    }
}
