<?php

declare(strict_types=1);

namespace Pulsar\Codegen\Schema;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Metadata for a relationship between entities.
 *
 * @phpstan-type RelationshipArray array{
 *     type: string,
 *     relatedEntity: string,
 *     foreignKey: string,
 *     localKey: string,
 *     pivotTable: string|null,
 * }
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class RelationshipDefinition
{
    public function __construct(
        public RelationType $type,
        public string $relatedEntity,
        public string $foreignKey,
        public string $localKey,
        public ?string $pivotTable = null,
    ) {}

    /**
     * @return RelationshipArray
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'relatedEntity' => $this->relatedEntity,
            'foreignKey' => $this->foreignKey,
            'localKey' => $this->localKey,
            'pivotTable' => $this->pivotTable,
        ];
    }

    /**
     * @param array{
     *     type?: string,
     *     relatedEntity?: string,
     *     foreignKey?: string,
     *     localKey?: string,
     *     pivotTable?: string|null,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            type: RelationType::tryFrom($data['type'] ?? '') ?? RelationType::HasMany,
            relatedEntity: $data['relatedEntity'] ?? '',
            foreignKey: $data['foreignKey'] ?? '',
            localKey: $data['localKey'] ?? '',
            pivotTable: $data['pivotTable'] ?? null,
        );
    }
}
