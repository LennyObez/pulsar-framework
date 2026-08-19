<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\TenancyConfig;
use Pulsar\Config\TenantDatabaseConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionManagerInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Tenancy\Middleware\TenantResolutionMiddleware;
use Pulsar\Tenancy\Resolver\HeaderTenantResolver;
use Pulsar\Tenancy\Resolver\PathPrefixTenantResolver;
use Pulsar\Tenancy\Resolver\SubdomainTenantResolver;
use Pulsar\Tenancy\TenantAwareConnectionManager;
use Pulsar\Tenancy\TenantContext;
use Pulsar\Tenancy\TenantResolverInterface;
use Pulsar\Tenancy\TenantResolverStrategy;

#[Internal]
final readonly class TenancyWiring implements ServiceWiringInterface
{
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $repository = $configManager->repository();

        if (!$repository->has(TenancyConfig::class)) {
            return;
        }

        /** @var TenancyConfig $tenancyConfig */
        $tenancyConfig = $repository->get(TenancyConfig::class);
        $container->instance(TenancyConfig::class, $tenancyConfig);
        $container->instance(TenantDatabaseConfig::class, $tenancyConfig->database);

        if (!$tenancyConfig->enabled) {
            return;
        }

        // Tenant context
        $tenantContext = new TenantContext();
        $container->instance(TenantContext::class, $tenantContext);

        // Resolver
        $resolver = match ($tenancyConfig->resolver) {
            TenantResolverStrategy::Header => new HeaderTenantResolver($tenancyConfig),
            TenantResolverStrategy::Subdomain => new SubdomainTenantResolver($tenancyConfig),
            TenantResolverStrategy::Path => new PathPrefixTenantResolver($tenancyConfig),
        };

        $container->instance(TenantResolverInterface::class, $resolver);
        $container->instance($resolver::class, $resolver);

        // Middleware
        $logger = $container->has(LoggerInterface::class)
            ? $container->get(LoggerInterface::class)
            : null;

        /** @var LoggerInterface|null $logger */
        $tenantMiddleware = new TenantResolutionMiddleware($resolver, $tenantContext, $tenancyConfig, $logger);
        $container->instance(TenantResolutionMiddleware::class, $tenantMiddleware);

        // Binding it is not running it. Without this pipe the middleware was
        // constructed, registered, and never executed: TenantContext stayed empty on
        // every request, and everything downstream that scopes by tenant — the
        // connection manager, the idempotency store, queue jobs, model binding, the
        // payments handlers — ran unscoped while looking correctly configured.
        // Every other wiring in WiringList pipes what it builds; this one did not.
        $middleware->pipe($tenantMiddleware);

        // Tenant-aware connection manager (decorate existing if available)
        if ($container->has(ConnectionManagerInterface::class)) {
            /** @var ConnectionManagerInterface $innerManager */
            $innerManager = $container->get(ConnectionManagerInterface::class);

            $tenantAwareManager = new TenantAwareConnectionManager($innerManager, $tenantContext, $tenancyConfig);
            $container->instance(TenantAwareConnectionManager::class, $tenantAwareManager);

            // Rebind the interface itself so every consumer that resolves
            // ConnectionManagerInterface at request time receives the tenant-aware
            // decorator and routes to the correct tenant connection. Without this
            // the decorator was built but never used — consumers kept the inner,
            // non-tenant-aware manager. The decorator delegates to the inner
            // manager whenever no tenant context is resolved (boot, non-tenant
            // requests), so behaviour outside a resolved tenant scope is unchanged.
            $container->instance(ConnectionManagerInterface::class, $tenantAwareManager);
        }
    }
}
