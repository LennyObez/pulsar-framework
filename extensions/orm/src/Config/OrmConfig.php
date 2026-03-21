<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Top-level ORM configuration DTO.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class OrmConfig
{
    public function __construct(
        public string $connection,
        public MetadataCacheConfig $metadataCache,
        public EncryptionConfig $encryption,
        public string $tenantColumn,
        public string $softDeleteColumn,
    ) {}

    /**
     * Build from the raw ORM config array.
     *
     * @param array<string, mixed> $data Raw array from config/orm.php
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var string $connection */
        $connection = $data['connection'] ?? 'default';

        /** @var array<string, mixed> $metadataCacheData */
        $metadataCacheData = $data['metadata_cache'] ?? [];

        /** @var array<string, mixed> $encryptionData */
        $encryptionData = $data['encryption'] ?? [];

        /** @var string $tenantColumn */
        $tenantColumn = $data['tenant_column'] ?? 'tenant_id';

        /** @var string $softDeleteColumn */
        $softDeleteColumn = $data['soft_delete_column'] ?? 'deleted_at';

        return new self(
            connection: $connection,
            metadataCache: MetadataCacheConfig::fromArray($metadataCacheData),
            encryption: EncryptionConfig::fromArray($encryptionData),
            tenantColumn: $tenantColumn,
            softDeleteColumn: $softDeleteColumn,
        );
    }
}
