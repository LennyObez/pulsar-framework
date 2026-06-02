<?php

declare(strict_types=1);

namespace Pulsar\Tenancy\Resolver;

use Override;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Config\TenancyConfig;
use Pulsar\Tenancy\Tenant;
use Pulsar\Tenancy\TenantResolverInterface;

use function preg_replace;
use function str_ends_with;
use function strlen;
use function substr;

/**
 * Resolves tenants from the subdomain portion of the Host header.
 *
 * Given a subdomain suffix of '.example.com', a Host of 'acme.example.com'
 * resolves to tenant 'acme'.
 */
final readonly class SubdomainTenantResolver implements TenantResolverInterface
{
    public function __construct(
        private TenancyConfig $config,
    ) {}

    #[Override]
    public function resolve(ServerRequestInterface $request): ?Tenant
    {
        $host = $request->getHeaderLine('Host');

        if ($host === '') {
            return null;
        }

        // Strip a trailing ":port" only. Using a digits-anchored pattern keeps
        // bracketed IPv6 literals (e.g. "[::1]:8080") intact instead of
        // truncating at the first colon inside the address.
        $host = (string) preg_replace('/:\d+$/', '', $host);

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
