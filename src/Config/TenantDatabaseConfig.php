<?php

declare(strict_types=1);

namespace Pulsar\Config;

use Pulsar\Api\Api;
use Pulsar\Tenancy\TenantDatabaseStrategy;

/**
 * Tenant database isolation configuration sub-DTO.
 */
#[Api]
readonly class TenantDatabaseConfig
{
    public function __construct(
        public TenantDatabaseStrategy $strategy = TenantDatabaseStrategy::Prefix,
        public string $prefixTemplate = 'tenant_{tenant_id}_',
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var string $strategyValue */
        $strategyValue = $data['strategy'] ?? 'prefix';

        /** @var string $prefixTemplate */
        $prefixTemplate = $data['prefix_template'] ?? 'tenant_{tenant_id}_';

        return new self(
            strategy: TenantDatabaseStrategy::from($strategyValue),
            prefixTemplate: $prefixTemplate,
        );
    }
}
