<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Api\Resource\ComplexityLimits;
use Pulsar\Api\Resource\ResourceMetadataCache;
use Pulsar\Api\Security\FieldAuthorizer;
use Pulsar\Config\ApiConfig;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;

/**
 * Wires API tooling services into the container.
 *
 * Registers the field authorizer, the resource metadata cache and the
 * complexity limits.
 */
#[Internal]
final readonly class ApiWiring implements ServiceWiringInterface
{
    #[Override]
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $repository = $configManager->repository();

        if (!$repository->has(ApiConfig::class)) {
            return;
        }

        /** @var ApiConfig $apiConfig */
        $apiConfig = $repository->get(ApiConfig::class);

        // Complexity limits
        $complexityLimits = $apiConfig->complexityLimits;
        $container->instance(ComplexityLimits::class, $complexityLimits);

        // Field authorizer
        $fieldAuthorizer = new FieldAuthorizer();
        $container->instance(FieldAuthorizer::class, $fieldAuthorizer);

        // Resource metadata cache
        $metadataCache = new ResourceMetadataCache();
        $container->instance(ResourceMetadataCache::class, $metadataCache);
    }
}
