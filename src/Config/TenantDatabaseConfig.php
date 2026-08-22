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
final readonly class TenantDatabaseConfig implements ReportsUnknownKeys
{
    /** Keys read from the `database` sub-array of config/tenancy.php. */
    private const array KNOWN_KEYS = ['strategy', 'prefix_template'];

    /**
     * @param list<string> $unknownKeys Keys present in the raw tenant `database` array
     *     that this DTO does not read — a misspelled `prefix_template` silently returns
     *     every tenant to the default naming, which is a tenant-isolation setting.
     */
    public function __construct(
        public TenantDatabaseStrategy $strategy = TenantDatabaseStrategy::Prefix,
        public string $prefixTemplate = 'tenant_{tenant_id}_',
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
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }
}
