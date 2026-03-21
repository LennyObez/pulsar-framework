<?php

declare(strict_types=1);

namespace Pulsar\Tenancy\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Pulsar\Config\TenancyConfig;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Tenancy\Tenant;
use Pulsar\Tenancy\TenantContext;
use Pulsar\Tenancy\TenantResolverInterface;

/**
 * Middleware that resolves the current tenant and sets context.
 */
final readonly class TenantResolutionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private TenantResolverInterface $resolver,
        private TenantContext $context,
        private TenancyConfig $config,
        private ?LoggerInterface $logger = null,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $tenant = $this->resolver->resolve($request);

        if ($tenant === null && $this->config->defaultTenant !== null) {
            $tenantId = $this->config->defaultTenant;

            if (isset($this->config->tenants[$tenantId])) {
                /** @var array<string, mixed> $tenantData */
                $tenantData = $this->config->tenants[$tenantId];
                $tenant = Tenant::fromArray($tenantId, $tenantData);
            }
        }

        if ($tenant !== null) {
            $this->context->set($tenant);
            $request = $request->withAttribute('_tenant', $tenant);

            $this->logger?->info('Tenant resolved', [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
            ]);
        } else {
            $this->logger?->info('No tenant resolved for request');
        }

        return $handler->handle($request);
    }
}
