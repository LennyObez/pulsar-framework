<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Tenancy\TenantResolverStrategy;

/**
 * Typed configuration DTO for `config/tenancy.php`.
 */
#[Api]
readonly class TenancyConfig
{
    /**
     * @param array<string, array<string, mixed>> $tenants Map of tenant ID → tenant data
     */
    public function __construct(
        public bool $enabled = false,
        public TenantResolverStrategy $resolver = TenantResolverStrategy::Header,
        public string $headerName = 'X-Tenant-ID',
        public string $subdomainSuffix = '',
        public string $pathPrefix = '/t/',
        public ?string $defaultTenant = null,
        public TenantDatabaseConfig $database = new TenantDatabaseConfig(),
        public array $tenants = [],
    ) {}

    /**
     * @param array<string, mixed> $data Raw array from config/tenancy.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $enabled = $environment->get('TENANCY_ENABLED') !== null
            ? $environment->get('TENANCY_ENABLED') === 'true'
            : (bool) ($data['enabled'] ?? false);

        /** @var string $resolverValue */
        $resolverValue = $data['resolver'] ?? 'header';
        $resolver = TenantResolverStrategy::from($resolverValue);

        /** @var array<string, mixed> $dbData */
        $dbData = $data['database'] ?? [];

        /** @var array<string, array<string, mixed>> $tenants */
        $tenants = $data['tenants'] ?? [];

        /** @var string $headerName */
        $headerName = $data['header_name'] ?? 'X-Tenant-ID';
        /** @var string $subdomainSuffix */
        $subdomainSuffix = $data['subdomain_suffix'] ?? '';
        /** @var string $pathPrefix */
        $pathPrefix = $data['path_prefix'] ?? '/t/';
        /** @var string|null $defaultTenant */
        $defaultTenant = $data['default_tenant'] ?? null;

        return new self(
            enabled: $enabled,
            resolver: $resolver,
            headerName: $headerName,
            subdomainSuffix: $subdomainSuffix,
            pathPrefix: $pathPrefix,
            defaultTenant: $defaultTenant,
            database: TenantDatabaseConfig::fromArray($dbData),
            tenants: $tenants,
        );
    }
}
