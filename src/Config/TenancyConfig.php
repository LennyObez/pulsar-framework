<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Tenancy\Exception\TenancyException;
use Pulsar\Tenancy\TenantResolverStrategy;

use function sprintf;

/**
 * Typed configuration DTO for `config/tenancy.php`.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TenancyConfig implements ReportsUnknownKeys
{
    /** Keys recognised in config/tenancy.php. */
    private const array KNOWN_KEYS = [
        'enabled', 'resolver', 'header_name', 'subdomain_suffix', 'path_prefix',
        'default_tenant', 'database', 'tenants',
    ];

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
        /** @var list<string> */
        public array $unknownKeys = [],
    ) {}

    /**
     * @return list<string>
     */
    public function unknownConfigKeys(): array
    {
        return $this->unknownKeys;
    }

    /**
     * @param array{
     *     enabled?: bool|int|string,
     *     resolver?: string,
     *     header_name?: string,
     *     subdomain_suffix?: string,
     *     path_prefix?: string,
     *     default_tenant?: string|null,
     *     database?: array{strategy?: string, prefix_template?: string},
     *     tenants?: array<string, array<string, mixed>>,
     * } $data Raw array from config/tenancy.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $enabled = $environment->get('TENANCY_ENABLED') !== null
            ? $environment->get('TENANCY_ENABLED') === 'true'
            : (bool) ($data['enabled'] ?? false);

        $resolverValue = $data['resolver'] ?? 'header';
        $resolver = TenantResolverStrategy::tryFrom($resolverValue)
            ?? throw TenancyException::invalidConfiguration(sprintf(
                'Unknown resolver strategy "%s". Expected one of: header, subdomain, path.',
                $resolverValue,
            ));

        return new self(
            enabled: $enabled,
            resolver: $resolver,
            headerName: $data['header_name'] ?? 'X-Tenant-ID',
            subdomainSuffix: $data['subdomain_suffix'] ?? '',
            pathPrefix: $data['path_prefix'] ?? '/t/',
            defaultTenant: $data['default_tenant'] ?? null,
            database: TenantDatabaseConfig::fromArray($data['database'] ?? []),
            tenants: $data['tenants'] ?? [],
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }
}
