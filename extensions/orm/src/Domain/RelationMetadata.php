<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Domain;

use Pulsar\Api\Api;

/**
 * Metadata for a single entity relation mapping.
 */
#[Api(since: '1.0.0')]
final readonly class RelationMetadata
{
    /**
     * @param string $propertyName PHP property name
     * @param RelationType $type Relation type
     * @param class-string $targetEntity Target entity class
     * @param string $foreignKey Foreign key column
     * @param string $localKey Local key column
     * @param string|null $pivotTable Pivot table (BelongsToMany / MorphToMany)
     * @param string|null $pivotForeignKey Pivot FK for this entity
     * @param string|null $pivotRelatedKey Pivot FK for the related entity
     * @param string|null $morphTypeColumn Column storing the entity type (MorphTo/MorphMany/MorphToMany)
     * @param string|null $morphIdColumn Column storing the entity ID (MorphTo/MorphMany/MorphToMany)
     * @param class-string|null $throughEntity Intermediate entity class (HasManyThrough)
     * @param string|null $throughForeignKey FK on intermediate table referencing this entity
     * @param string|null $throughLocalKey FK on intermediate table referencing the target entity
     */
    public function __construct(
        public string $propertyName,
        public RelationType $type,
        public string $targetEntity,
        public string $foreignKey,
        public string $localKey,
        public ?string $pivotTable = null,
        public ?string $pivotForeignKey = null,
        public ?string $pivotRelatedKey = null,
        public ?string $morphTypeColumn = null,
        public ?string $morphIdColumn = null,
        public ?string $throughEntity = null,
        public ?string $throughForeignKey = null,
        public ?string $throughLocalKey = null,
    ) {}
}
