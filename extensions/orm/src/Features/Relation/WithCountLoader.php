<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Relation;

use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Domain\RawExpression;
use Pulsar\Extension\Orm\Domain\RelationMetadata;
use Pulsar\Extension\Orm\Features\Query\SelectBuilder;
use ReflectionClass;

use function sprintf;

/**
 * Loads relation counts without loading the actual related entities.
 *
 * Adds a `{relation}_count` property value to each entity.
 */
#[Internal]
final readonly class WithCountLoader
{
    public function __construct(
        private ConnectionInterface $connection,
        private MetadataRegistryInterface $metadataRegistry,
    ) {}

    /**
     * Load relation counts for a set of entities.
     *
     * @param list<object> $entities
     * @param list<string> $relations Relation names to count
     * @return array<string, array<string, int>> Map of relation name => (parent PK => count)
     */
    public function loadCounts(array $entities, array $relations): array
    {
        if ($entities === []) {
            return [];
        }

        $entityClass = $entities[0]::class;
        $metadata = $this->metadataRegistry->get($entityClass);
        $counts = [];

        foreach ($relations as $relationName) {
            $relation = $metadata->relations[$relationName] ?? null;
            if ($relation === null) {
                continue;
            }

            $counts[$relationName] = $this->countRelation($entities, $relation, $metadata->primaryKey->propertyName);
        }

        return $counts;
    }

    /**
     * @param list<object> $entities
     * @return array<string, int>
     */
    private function countRelation(array $entities, RelationMetadata $relation, string $pkProperty): array
    {
        $parentIds = [];
        foreach ($entities as $entity) {
            $ref = new ReflectionClass($entity);
            $parentIds[] = $ref->getProperty($pkProperty)->getValue($entity);
        }

        if ($parentIds === []) {
            return [];
        }

        $targetMetadata = $this->metadataRegistry->get($relation->targetEntity);
        $builder = new SelectBuilder($this->connection);
        $builder->from($targetMetadata->tableName);
        $builder->select([
            RawExpression::of(sprintf(
                '%s, COUNT(*) AS cnt',
                $relation->foreignKey,
            )),
        ]);
        $builder->whereIn($relation->foreignKey, $parentIds);
        $builder->groupBy($relation->foreignKey);

        $result = $builder->get();
        $countMap = [];
        foreach ($result->rows as $row) {
            $key = (string) $row->get($relation->foreignKey);
            $countMap[$key] = $row->getInt('cnt');
        }

        return $countMap;
    }
}
