<?php

declare(strict_types=1);

namespace Pulsar\Tenancy\Resolver;

use Override;
use Pulsar\Config\TenancyConfig;
use Pulsar\Http\Request;
use Pulsar\Tenancy\Tenant;
use Pulsar\Tenancy\TenantResolverInterface;

use function str_starts_with;
use function strlen;
use function strpos;
use function substr;

/**
 * Resolves tenants from a URL path prefix.
 *
 * Given a path prefix of '/t/', a path of '/t/acme/dashboard'
 * resolves to tenant 'acme'.
 */
readonly class PathPrefixTenantResolver implements TenantResolverInterface
{
    public function __construct(
        private TenancyConfig $config,
    ) {}

    #[Override]
    public function resolve(Request $request): ?Tenant
    {
        $path = $request->path;

        if (!str_starts_with($path, $this->config->pathPrefix)) {
            return null;
        }

        $remainder = substr($path, strlen($this->config->pathPrefix));

        if ($remainder === '') {
            return null;
        }

        $slashPos = strpos($remainder, '/');
        $tenantId = $slashPos !== false ? substr($remainder, 0, $slashPos) : $remainder;

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
