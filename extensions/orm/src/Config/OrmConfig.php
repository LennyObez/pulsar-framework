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
     * @param array{
     *     connection?: string,
     *     metadata_cache?: array<string, mixed>,
     *     encryption?: array<string, mixed>,
     *     tenant_column?: string,
     *     soft_delete_column?: string,
     * } $data Raw array from config/orm.php
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            connection: $data['connection'] ?? 'default',
            metadataCache: MetadataCacheConfig::fromArray($data['metadata_cache'] ?? []),
            encryption: EncryptionConfig::fromArray($data['encryption'] ?? []),
            tenantColumn: $data['tenant_column'] ?? 'tenant_id',
            softDeleteColumn: $data['soft_delete_column'] ?? 'deleted_at',
        );
    }
}
