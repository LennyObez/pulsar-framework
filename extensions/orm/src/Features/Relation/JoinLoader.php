<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Relation;

use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Orm\Contracts\EntityHydratorInterface;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Domain\RelationType;
use Pulsar\Extension\Orm\Domain\RelationMetadata;
use Pulsar\Extension\Orm\Features\Query\SelectBuilder;

use function sprintf;

/**
 * Loads relations via JOINs instead of separate queries.
 *
 * Useful for single BelongsTo relations where a JOIN is more efficient
 * than a separate query.
 */
#[Internal]
final class JoinLoader
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly MetadataRegistryInterface $metadataRegistry,
        private readonly EntityHydratorInterface $hydrator,
    ) {}

    /**
     * Apply a JOIN-based relation load to a query builder.
     */
    public function applyJoin(
        SelectBuilder $builder,
        RelationMetadata $relation,
        string $parentAlias,
        string $joinAlias,
    ): void {
        if ($relation->type !== RelationType::BelongsTo && $relation->type !== RelationType::HasOne) {
            return;
        }

        $targetMetadata = $this->metadataRegistry->get($relation->targetEntity);

        $leftColumn = match ($relation->type) {
            RelationType::BelongsTo => sprintf('%s.%s', $parentAlias, $relation->foreignKey),
            default => sprintf('%s.%s', $parentAlias, $relation->localKey),
        };

        $rightColumn = match ($relation->type) {
            RelationType::BelongsTo => sprintf('%s.%s', $joinAlias, $relation->localKey),
            default => sprintf('%s.%s', $joinAlias, $relation->foreignKey),
        };

        $builder->leftJoin(
            $targetMetadata->tableName,
            $joinAlias,
            static fn(\Pulsar\Extension\Orm\Features\Query\JoinOnBuilder $on) => $on->on($leftColumn, '=', $rightColumn),
        );
    }
}
