<?php

declare(strict_types=1);

namespace Pulsar\Codegen\Schema;

use NoDiscard;
use Pulsar\Api\Api;

use function is_string;

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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $rawType = is_string($data['type'] ?? null) ? $data['type'] : '';

        return new self(
            type: RelationType::from($rawType),
            relatedEntity: is_string($data['relatedEntity'] ?? null) ? $data['relatedEntity'] : '',
            foreignKey: is_string($data['foreignKey'] ?? null) ? $data['foreignKey'] : '',
            localKey: is_string($data['localKey'] ?? null) ? $data['localKey'] : '',
            pivotTable: is_string($data['pivotTable'] ?? null) ? $data['pivotTable'] : null,
        );
    }
}
