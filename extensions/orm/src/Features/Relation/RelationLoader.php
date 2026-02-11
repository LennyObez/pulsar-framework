<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Relation;

use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Orm\Contracts\EntityHydratorInterface;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Domain\FetchPlan;
use Pulsar\Extension\Orm\Domain\RelationMetadata;
use Pulsar\Extension\Orm\Domain\RelationType;
use Pulsar\Extension\Orm\Features\Query\SelectBuilder;
use ReflectionProperty;

use function array_map;
use function array_unique;
use function array_values;
use function count;

/**
 * Loads entity relations based on a FetchPlan.
 *
 * No lazy loading — all relations must be declared upfront.
 */
#[Internal]
final readonly class RelationLoader
{
    public function __construct(
        private ConnectionInterface $connection,
        private MetadataRegistryInterface $metadataRegistry,
        private EntityHydratorInterface $hydrator,
    ) {}

    /**
     * Load relations for a list of entities according to the fetch plan.
     *
     * @param list<object> $entities
     */
    public function loadRelations(array $entities, FetchPlan $fetchPlan): void
    {
        if ($entities === [] || $fetchPlan->isEmpty()) {
            return;
        }

        $entityClass = $entities[0]::class;
        $metadata = $this->metadataRegistry->get($entityClass);

        foreach ($fetchPlan->relationNames() as $relationName) {
            $relation = $metadata->relations[$relationName] ?? null;
            if ($relation === null) {
                continue;
            }

            $this->loadRelation($entities, $relation, $fetchPlan->nested($relationName));
        }
    }

    /**
     * @param list<object> $entities
     */
    private function loadRelation(
        array $entities,
        RelationMetadata $relation,
        ?FetchPlan $nestedPlan,
    ): void {
        match ($relation->type) {
            RelationType::BelongsTo => $this->loadBelongsTo($entities, $relation, $nestedPlan),
            RelationType::HasOne => $this->loadHasOne($entities, $relation, $nestedPlan),
            RelationType::HasMany => $this->loadHasMany($entities, $relation, $nestedPlan),
            RelationType::BelongsToMany => $this->loadBelongsToMany($entities, $relation, $nestedPlan),
        };
    }

    /**
     * @param list<object> $entities
     */
    private function loadBelongsTo(array $entities, RelationMetadata $relation, ?FetchPlan $nestedPlan): void
    {
        $foreignKeys = $this->extractValues($entities, $relation->foreignKey);
        $foreignKeys = array_unique($foreignKeys);

        if ($foreignKeys === []) {
            return;
        }

        $targetMetadata = $this->metadataRegistry->get($relation->targetEntity);
        $builder = new SelectBuilder($this->connection);
        $builder->forEntity($relation->targetEntity, $targetMetadata, $this->hydrator);
        $builder->whereIn($relation->localKey, $foreignKeys);

        /** @var list<object> $related */
        $related = $builder->getEntities();

        // Index by local key
        $indexed = [];
        foreach ($related as $relatedEntity) {
            $prop = new ReflectionProperty($relatedEntity, $targetMetadata->primaryKey->propertyName);
            $key = (string) $prop->getValue($relatedEntity);
            $indexed[$key] = $relatedEntity;
        }

        // Assign to parent entities
        foreach ($entities as $entity) {
            $fkProp = new ReflectionProperty($entity, $relation->foreignKey);
            $fkValue = (string) $fkProp->getValue($entity);
            $relProp = new ReflectionProperty($entity, $relation->propertyName);
            $relProp->setValue($entity, $indexed[$fkValue] ?? null);
        }

        // Load nested relations
        if ($nestedPlan !== null && count($related) > 0) {
            $this->loadRelations($related, $nestedPlan);
        }
    }

    /**
     * @param list<object> $entities
     */
    private function loadHasOne(array $entities, RelationMetadata $relation, ?FetchPlan $nestedPlan): void
    {
        $localKeys = $this->extractPrimaryKeys($entities);

        if ($localKeys === []) {
            return;
        }

        $targetMetadata = $this->metadataRegistry->get($relation->targetEntity);
        $builder = new SelectBuilder($this->connection);
        $builder->forEntity($relation->targetEntity, $targetMetadata, $this->hydrator);
        $builder->whereIn($relation->foreignKey, $localKeys);

        /** @var list<object> $related */
        $related = $builder->getEntities();

        // Index by foreign key
        $indexed = [];
        foreach ($related as $relatedEntity) {
            $fkCol = $targetMetadata->columnByName($relation->foreignKey);
            if ($fkCol !== null) {
                $prop = new ReflectionProperty($relatedEntity, $fkCol->propertyName);
                $key = (string) $prop->getValue($relatedEntity);
                $indexed[$key] = $relatedEntity;
            }
        }

        // Assign to parent entities
        $parentMetadata = $this->metadataRegistry->get($entities[0]::class);
        foreach ($entities as $entity) {
            $pkProp = new ReflectionProperty($entity, $parentMetadata->primaryKey->propertyName);
            $pkValue = (string) $pkProp->getValue($entity);
            $relProp = new ReflectionProperty($entity, $relation->propertyName);
            $relProp->setValue($entity, $indexed[$pkValue] ?? null);
        }

        if ($nestedPlan !== null && count($related) > 0) {
            $this->loadRelations($related, $nestedPlan);
        }
    }

    /**
     * @param list<object> $entities
     */
    private function loadHasMany(array $entities, RelationMetadata $relation, ?FetchPlan $nestedPlan): void
    {
        $localKeys = $this->extractPrimaryKeys($entities);

        if ($localKeys === []) {
            return;
        }

        $targetMetadata = $this->metadataRegistry->get($relation->targetEntity);
        $builder = new SelectBuilder($this->connection);
        $builder->forEntity($relation->targetEntity, $targetMetadata, $this->hydrator);
        $builder->whereIn($relation->foreignKey, $localKeys);

        /** @var list<object> $related */
        $related = $builder->getEntities();

        // Group by foreign key
        /** @var array<string, list<object>> $grouped */
        $grouped = [];
        foreach ($related as $relatedEntity) {
            $fkCol = $targetMetadata->columnByName($relation->foreignKey);
            if ($fkCol !== null) {
                $prop = new ReflectionProperty($relatedEntity, $fkCol->propertyName);
                $key = (string) $prop->getValue($relatedEntity);
                $grouped[$key][] = $relatedEntity;
            }
        }

        // Assign to parent entities
        $parentMetadata = $this->metadataRegistry->get($entities[0]::class);
        foreach ($entities as $entity) {
            $pkProp = new ReflectionProperty($entity, $parentMetadata->primaryKey->propertyName);
            $pkValue = (string) $pkProp->getValue($entity);
            $relProp = new ReflectionProperty($entity, $relation->propertyName);
            $relProp->setValue($entity, $grouped[$pkValue] ?? []);
        }

        if ($nestedPlan !== null && count($related) > 0) {
            $this->loadRelations($related, $nestedPlan);
        }
    }

    /**
     * @param list<object> $entities
     */
    private function loadBelongsToMany(array $entities, RelationMetadata $relation, ?FetchPlan $nestedPlan): void
    {
        if ($relation->pivotTable === null || $relation->pivotForeignKey === null || $relation->pivotRelatedKey === null) {
            return;
        }

        $localKeys = $this->extractPrimaryKeys($entities);
        if ($localKeys === []) {
            return;
        }

        // Query pivot table
        $pivotBuilder = new SelectBuilder($this->connection);
        $pivotBuilder->from($relation->pivotTable);
        $pivotBuilder->whereIn($relation->pivotForeignKey, $localKeys);
        $pivotRows = $pivotBuilder->get();

        // Collect related IDs grouped by parent key
        /** @var array<string, list<string>> $pivotMap */
        $pivotMap = [];
        $allRelatedIds = [];
        foreach ($pivotRows->rows as $row) {
            $parentId = (string) $row->get($relation->pivotForeignKey);
            $relatedId = (string) $row->get($relation->pivotRelatedKey);
            $pivotMap[$parentId][] = $relatedId;
            $allRelatedIds[] = $relatedId;
        }

        $allRelatedIds = array_values(array_unique($allRelatedIds));
        if ($allRelatedIds === []) {
            // No related entities found — set empty arrays
            foreach ($entities as $entity) {
                $relProp = new ReflectionProperty($entity, $relation->propertyName);
                $relProp->setValue($entity, []);
            }

            return;
        }

        // Load related entities
        $targetMetadata = $this->metadataRegistry->get($relation->targetEntity);
        $relatedBuilder = new SelectBuilder($this->connection);
        $relatedBuilder->forEntity($relation->targetEntity, $targetMetadata, $this->hydrator);
        $relatedBuilder->whereIn($targetMetadata->primaryKey->columnName, $allRelatedIds);
        /** @var list<object> $allRelated */
        $allRelated = $relatedBuilder->getEntities();

        // Index by PK
        $relatedIndex = [];
        foreach ($allRelated as $relatedEntity) {
            $prop = new ReflectionProperty($relatedEntity, $targetMetadata->primaryKey->propertyName);
            $key = (string) $prop->getValue($relatedEntity);
            $relatedIndex[$key] = $relatedEntity;
        }

        // Assign to parent entities
        $parentMetadata = $this->metadataRegistry->get($entities[0]::class);
        foreach ($entities as $entity) {
            $pkProp = new ReflectionProperty($entity, $parentMetadata->primaryKey->propertyName);
            $pkValue = (string) $pkProp->getValue($entity);
            $relProp = new ReflectionProperty($entity, $relation->propertyName);

            $relatedForParent = [];
            foreach ($pivotMap[$pkValue] ?? [] as $relatedId) {
                if (isset($relatedIndex[$relatedId])) {
                    $relatedForParent[] = $relatedIndex[$relatedId];
                }
            }
            $relProp->setValue($entity, $relatedForParent);
        }

        if ($nestedPlan !== null && count($allRelated) > 0) {
            $this->loadRelations($allRelated, $nestedPlan);
        }
    }

    /**
     * @param list<object> $entities
     * @return list<mixed>
     */
    private function extractPrimaryKeys(array $entities): array
    {
        if ($entities === []) {
            return [];
        }

        $metadata = $this->metadataRegistry->get($entities[0]::class);
        $pkProp = $metadata->primaryKey->propertyName;

        return array_values(array_unique(array_map(
            static function (object $entity) use ($pkProp): mixed {
                return new ReflectionProperty($entity, $pkProp)->getValue($entity);
            },
            $entities,
        )));
    }

    /**
     * @param list<object> $entities
     * @return list<mixed>
     */
    private function extractValues(array $entities, string $propertyName): array
    {
        return array_map(
            static function (object $entity) use ($propertyName): mixed {
                return new ReflectionProperty($entity, $propertyName)->getValue($entity);
            },
            $entities,
        );
    }
}
