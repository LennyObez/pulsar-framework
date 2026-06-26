<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Tenancy\Exception\TenancyException;
use Pulsar\Tenancy\TenantDatabaseStrategy;

use function sprintf;

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
        $strategyValue = $data['strategy'] ?? 'prefix';
        $strategy = TenantDatabaseStrategy::tryFrom($strategyValue)
            ?? throw TenancyException::invalidConfiguration(sprintf(
                'Unknown database strategy "%s". Expected one of: prefix, separate_connection, shared.',
                $strategyValue,
            ));

        return new self(
            strategy: $strategy,
            prefixTemplate: $data['prefix_template'] ?? 'tenant_{tenant_id}_',
        );
    }
}
