<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Attribute;

use Attribute;
use Pulsar\Api\Api;
use Pulsar\Extension\Orm\Domain\RelationType;

/**
 * Defines a relation between entities.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
#[Api(since: '1.0.0')]
final readonly class Relation
{
    /**
     * @param class-string $target Target entity class
     * @param string|null $foreignKey Foreign key column (on the owning side)
     * @param string|null $localKey Local key column (default: primary key)
     * @param string|null $pivotTable Pivot table name (for BelongsToMany/MorphToMany)
     * @param string|null $pivotForeignKey Foreign key on pivot table referencing this entity
     * @param string|null $pivotRelatedKey Foreign key on pivot table referencing the related entity
     * @param string|null $morphTypeColumn Column storing the entity type (for MorphTo/MorphMany/MorphToMany)
     * @param string|null $morphIdColumn Column storing the entity ID (for MorphTo/MorphMany/MorphToMany)
     * @param class-string|null $throughEntity Intermediate entity class (for HasManyThrough)
     * @param string|null $throughForeignKey FK on intermediate table referencing this entity
     * @param string|null $throughLocalKey FK on intermediate table referencing the target entity
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public RelationType $type,
        public string $target,
        public ?string $foreignKey = null,
        public ?string $localKey = null,
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
