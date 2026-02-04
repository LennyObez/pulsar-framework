<?php

declare(strict_types=1);

namespace Pulsar\Tenancy\Resolver;

use Pulsar\Config\TenancyConfig;
use Pulsar\Http\Request;
use Pulsar\Tenancy\Tenant;
use Pulsar\Tenancy\TenantResolverInterface;

/**
 * Resolves tenants from an HTTP header (e.g. X-Tenant-ID).
 */
readonly class HeaderTenantResolver implements TenantResolverInterface
{
    public function __construct(
        private TenancyConfig $config,
    ) {}

    public function resolve(Request $request): ?Tenant
    {
        $tenantId = $request->header($this->config->headerName);

        if ($tenantId === null || $tenantId === '') {
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
