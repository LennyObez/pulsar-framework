<?php

declare(strict_types=1);

namespace Pulsar\Tenancy\Resolver;

use Pulsar\Config\TenancyConfig;
use Pulsar\Http\Request;
use Pulsar\Tenancy\Tenant;
use Pulsar\Tenancy\TenantResolverInterface;

use function str_ends_with;
use function strlen;
use function substr;

/**
 * Resolves tenants from the subdomain portion of the Host header.
 *
 * Given a subdomain suffix of '.example.com', a Host of 'acme.example.com'
 * resolves to tenant 'acme'.
 */
readonly class SubdomainTenantResolver implements TenantResolverInterface
{
    public function __construct(
        private TenancyConfig $config,
    ) {}

    public function resolve(Request $request): ?Tenant
    {
        $host = $request->header('Host');

        if ($host === null || $host === '') {
            return null;
        }

        // Strip port if present
        $colonPos = strpos($host, ':');

        if ($colonPos !== false) {
            $host = substr($host, 0, $colonPos);
        }

        $suffix = $this->config->subdomainSuffix;

        if ($suffix === '' || !str_ends_with($host, $suffix)) {
            return null;
        }

        $tenantId = substr($host, 0, strlen($host) - strlen($suffix));

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
