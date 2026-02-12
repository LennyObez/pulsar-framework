<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Override;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Api\Resource\ComplexityLimits;
use Pulsar\Api\Resource\ResourceMetadataCache;
use Pulsar\Api\Security\EntitySerializationGuard;
use Pulsar\Api\Security\FieldAuthorizer;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Config\ApiConfig;
use Pulsar\Config\AppConfig;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;

/**
 * Wires API tooling services into the container.
 *
 * Registers field authorizer, metadata cache, complexity limits,
 * and the entity serialization guard.
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

        // Entity serialization guard
        /** @var AppConfig|null $appConfig */
        $appConfig = $repository->has(AppConfig::class) ? $repository->get(AppConfig::class) : null;
        $debugMode = $appConfig !== null ? $appConfig->debug : false;

        $auditLogger = $container->has(AuditLoggerInterface::class)
            ? $container->get(AuditLoggerInterface::class)
            : null;
        /** @var AuditLoggerInterface|null $auditLogger */

        $logger = $container->has(LoggerInterface::class)
            ? $container->get(LoggerInterface::class)
            : null;
        /** @var LoggerInterface|null $logger */

        if ($apiConfig->entitySerializationBanEnabled) {
            $guard = new EntitySerializationGuard(
                debugMode: $debugMode,
                entityNamespacePatterns: ['App\\Entity\\', 'App\\Model\\'],
                auditLogger: $auditLogger,
                logger: $logger,
            );
            $container->instance(EntitySerializationGuard::class, $guard);
        }
    }
}
