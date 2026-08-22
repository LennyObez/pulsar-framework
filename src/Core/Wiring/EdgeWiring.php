<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Pulsar\Api\Internal;
use Pulsar\Config\CallableConfigLoader;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\ContainerInterface;
use Pulsar\Edge\EdgeConfig;
use Pulsar\Edge\EdgeFunctionPipeline;
use Pulsar\Edge\EdgeMiddleware;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Http\TrustedProxy;
use Pulsar\Routing\Router;

/**
 * Wires the edge-function pipeline into the HTTP middleware stack.
 *
 * Opt-in: when config/edge.php enables it with at least one configured function
 * (A/B test or geo redirect), the {@see EdgeMiddleware} is piped so edge logic
 * (redirects, blocks, variant assignment) runs at the front of the request,
 * before routing. Edge functions remain usable as standalone building blocks at
 * a real CDN edge; this just runs the same blocks at the origin.
 *
 * Owns config/edge.php: its loader builds {@see EdgeConfig} into the
 * ConfigRepository during config load (the single source of truth), so wire()
 * resolves it from the repository and unknown-key reporting is handled once,
 * centrally, by ConfigManager's post-load sweep.
 */
#[Internal]
final readonly class EdgeWiring implements ServiceWiringInterface, ProvidesConfigLoaders
{
    public function configLoaders(): array
    {
        return [
            'edge' => new CallableConfigLoader(
                EdgeConfig::class,
                static fn(array $data): object => EdgeConfig::fromArray($data),
            ),
        ];
    }

    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $repository = $configManager->repository();
        $config = $repository->has(EdgeConfig::class)
            ? $repository->get(EdgeConfig::class)
            : new EdgeConfig();
        $container->instance(EdgeConfig::class, $config);

        if (!$config->isUsable()) {
            return;
        }

        $pipeline = new EdgeFunctionPipeline();
        foreach ($config->functions as $function) {
            $pipeline->add($function);
        }
        $container->instance(EdgeFunctionPipeline::class, $pipeline);

        $trustedProxy = $container->has(TrustedProxy::class)
            ? $container->get(TrustedProxy::class)
            : null;

        $edgeMiddleware = new EdgeMiddleware($pipeline, $config->geoCountryHeader, $trustedProxy);
        $container->instance(EdgeMiddleware::class, $edgeMiddleware);

        $middleware->pipe($edgeMiddleware);
    }
}
