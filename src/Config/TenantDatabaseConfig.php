<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Tenancy\TenantDatabaseStrategy;

/**
 * Tenant database isolation configuration sub-DTO.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TenantDatabaseConfig
{
    public function __construct(
        public TenantDatabaseStrategy $strategy = TenantDatabaseStrategy::Prefix,
        public string $prefixTemplate = 'tenant_{tenant_id}_',
    ) {}

    /**
     * @param array{
     *     strategy?: string,
     *     prefix_template?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            strategy: TenantDatabaseStrategy::from($data['strategy'] ?? 'prefix'),
            prefixTemplate: $data['prefix_template'] ?? 'tenant_{tenant_id}_',
        );
    }
}
