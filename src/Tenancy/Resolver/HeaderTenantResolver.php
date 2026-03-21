<?php

declare(strict_types=1);

namespace Pulsar\Tenancy\Resolver;

use Override;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Config\TenancyConfig;
use Pulsar\Tenancy\Tenant;
use Pulsar\Tenancy\TenantResolverInterface;

/**
 * Resolves tenants from an HTTP header (e.g. X-Tenant-ID).
 */
final readonly class HeaderTenantResolver implements TenantResolverInterface
{
    public function __construct(
        private TenancyConfig $config,
    ) {}

    #[Override]
    public function resolve(ServerRequestInterface $request): ?Tenant
    {
        $tenantId = $request->getHeaderLine($this->config->headerName);

        if ($tenantId === '') {
            return null;
        }

        if (!isset($this->config->tenants[$tenantId])) {
            return null;
        }

        /** @var array<string, mixed> $tenantData */
        $tenantData = $this->config->tenants[$tenantId];

        return Tenant::fromArray($tenantId, $tenantData);
    }
}
