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
     * @param string|null $pivotTable Pivot table name (for BelongsToMany)
     * @param string|null $pivotForeignKey Foreign key on pivot table referencing this entity
     * @param string|null $pivotRelatedKey Foreign key on pivot table referencing the related entity
     */
    public function __construct(
        public RelationType $type,
        public string $target,
        public ?string $foreignKey = null,
        public ?string $localKey = null,
        public ?string $pivotTable = null,
        public ?string $pivotForeignKey = null,
        public ?string $pivotRelatedKey = null,
    ) {}
}
