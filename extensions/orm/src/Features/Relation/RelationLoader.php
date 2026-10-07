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
use function is_scalar;

/**
 * Loads entity relations based on a FetchPlan.
 *
 * No lazy loading: all relations must be declared upfront.
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
            RelationType::MorphTo => $this->loadMorphTo($entities, $relation, $nestedPlan),
            RelationType::MorphMany => $this->loadMorphMany($entities, $relation, $nestedPlan),
            RelationType::HasManyThrough => $this->loadHasManyThrough($entities, $relation, $nestedPlan),
            RelationType::MorphToMany => $this->loadMorphToMany($entities, $relation, $nestedPlan),
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
        $builder->whereIn($relation->localKey, array_values($foreignKeys));

        /** @var list<object> $related */
        $related = $builder->getEntities();

        // Index by local key
        $indexed = [];
        foreach ($related as $relatedEntity) {
            $prop = new ReflectionProperty($relatedEntity, $targetMetadata->primaryKey->propertyName);
            $key = self::str($prop->getValue($relatedEntity));
            $indexed[$key] = $relatedEntity;
        }

        // Assign to parent entities
        foreach ($entities as $entity) {
            $fkProp = new ReflectionProperty($entity, $relation->foreignKey);
            $fkValue = self::str($fkProp->getValue($entity));
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
                $key = self::str($prop->getValue($relatedEntity));
                $indexed[$key] = $relatedEntity;
            }
        }

        // Assign to parent entities
        $parentMetadata = $this->metadataRegistry->get($entities[0]::class);
        foreach ($entities as $entity) {
            $pkProp = new ReflectionProperty($entity, $parentMetadata->primaryKey->propertyName);
            $pkValue = self::str($pkProp->getValue($entity));
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
                $key = self::str($prop->getValue($relatedEntity));
                $grouped[$key][] = $relatedEntity;
            }
        }

        // Assign to parent entities
        $parentMetadata = $this->metadataRegistry->get($entities[0]::class);
        foreach ($entities as $entity) {
            $pkProp = new ReflectionProperty($entity, $parentMetadata->primaryKey->propertyName);
            $pkValue = self::str($pkProp->getValue($entity));
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
            $parentId = self::str($row->get($relation->pivotForeignKey));
            $relatedId = self::str($row->get($relation->pivotRelatedKey));
            $pivotMap[$parentId][] = $relatedId;
            $allRelatedIds[] = $relatedId;
        }

        $allRelatedIds = array_values(array_unique($allRelatedIds));
        if ($allRelatedIds === []) {
            // No related entities found: set empty arrays
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
            $key = self::str($prop->getValue($relatedEntity));
            $relatedIndex[$key] = $relatedEntity;
        }

        // Assign to parent entities
        $parentMetadata = $this->metadataRegistry->get($entities[0]::class);
        foreach ($entities as $entity) {
            $pkProp = new ReflectionProperty($entity, $parentMetadata->primaryKey->propertyName);
            $pkValue = self::str($pkProp->getValue($entity));
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
     * Load a polymorphic MorphTo relation (inverse side).
     *
     * Each parent entity has a type column and an ID column that together
     * identify the related entity class and primary key.
     *
     * @param list<object> $entities
     */
    private function loadMorphTo(array $entities, RelationMetadata $relation, ?FetchPlan $nestedPlan): void
    {
        if ($relation->morphTypeColumn === null || $relation->morphIdColumn === null) {
            return;
        }

        // Group parent entities by morph type
        /** @var array<string, list<array{entity: object, morphId: string}>> $grouped */
        $grouped = [];
        foreach ($entities as $entity) {
            $typeProp = new ReflectionProperty($entity, $relation->morphTypeColumn);
            $idProp = new ReflectionProperty($entity, $relation->morphIdColumn);
            $type = self::str($typeProp->getValue($entity));
            $id = self::str($idProp->getValue($entity));

            if ($type !== '' && $id !== '') {
                $grouped[$type][] = ['entity' => $entity, 'morphId' => $id];
            }
        }

        // Load related entities for each type
        /** @var array<string, array<string, object>> $relatedByType */
        $relatedByType = [];
        foreach ($grouped as $type => $items) {
            /** @var class-string $morphClass */
            $morphClass = $type;

            if (!$this->metadataRegistry->has($morphClass)) {
                continue;
            }

            $ids = array_values(array_unique(array_map(
                static fn(array $item): string => $item['morphId'],
                $items,
            )));

            $targetMetadata = $this->metadataRegistry->get($morphClass);
            $builder = new SelectBuilder($this->connection);
            $builder->forEntity($morphClass, $targetMetadata, $this->hydrator);
            $builder->whereIn($targetMetadata->primaryKey->columnName, $ids);

            /** @var list<object> $related */
            $related = $builder->getEntities();

            $indexed = [];
            foreach ($related as $relatedEntity) {
                $prop = new ReflectionProperty($relatedEntity, $targetMetadata->primaryKey->propertyName);
                $key = self::str($prop->getValue($relatedEntity));
                $indexed[$key] = $relatedEntity;
            }

            $relatedByType[$type] = $indexed;

            if ($nestedPlan !== null && count($related) > 0) {
                $this->loadRelations($related, $nestedPlan);
            }
        }

        // Assign to parent entities
        foreach ($entities as $entity) {
            $typeProp = new ReflectionProperty($entity, $relation->morphTypeColumn);
            $idProp = new ReflectionProperty($entity, $relation->morphIdColumn);
            $type = self::str($typeProp->getValue($entity));
            $id = self::str($idProp->getValue($entity));
            $relProp = new ReflectionProperty($entity, $relation->propertyName);

            $relProp->setValue($entity, $relatedByType[$type][$id] ?? null);
        }
    }

    /**
     * Load a polymorphic MorphMany relation (owning side).
     *
     * The target entity has a type column and an ID column. This loads
     * all target entities whose type matches the parent class and whose
     * ID matches the parent primary key.
     *
     * @param list<object> $entities
     */
    private function loadMorphMany(array $entities, RelationMetadata $relation, ?FetchPlan $nestedPlan): void
    {
        if ($relation->morphTypeColumn === null || $relation->morphIdColumn === null) {
            return;
        }

        $localKeys = $this->extractPrimaryKeys($entities);
        if ($localKeys === []) {
            return;
        }

        $parentClass = $entities[0]::class;
        $targetMetadata = $this->metadataRegistry->get($relation->targetEntity);
        $builder = new SelectBuilder($this->connection);
        $builder->forEntity($relation->targetEntity, $targetMetadata, $this->hydrator);
        $builder->where($relation->morphTypeColumn, $parentClass);
        $builder->whereIn($relation->morphIdColumn, $localKeys);

        /** @var list<object> $related */
        $related = $builder->getEntities();

        // Group by morph ID
        /** @var array<string, list<object>> $grouped */
        $grouped = [];
        foreach ($related as $relatedEntity) {
            $morphIdCol = $targetMetadata->columnByName($relation->morphIdColumn);
            if ($morphIdCol !== null) {
                $prop = new ReflectionProperty($relatedEntity, $morphIdCol->propertyName);
                $key = self::str($prop->getValue($relatedEntity));
                $grouped[$key][] = $relatedEntity;
            }
        }

        // Assign to parent entities
        $parentMetadata = $this->metadataRegistry->get($parentClass);
        foreach ($entities as $entity) {
            $pkProp = new ReflectionProperty($entity, $parentMetadata->primaryKey->propertyName);
            $pkValue = self::str($pkProp->getValue($entity));
            $relProp = new ReflectionProperty($entity, $relation->propertyName);
            $relProp->setValue($entity, $grouped[$pkValue] ?? []);
        }

        if ($nestedPlan !== null && count($related) > 0) {
            $this->loadRelations($related, $nestedPlan);
        }
    }

    /**
     * Load a HasManyThrough relation.
     *
     * Queries through an intermediate table: parent → intermediate → target.
     * E.g., Country → Users → Posts (country has many posts through users).
     *
     * @param list<object> $entities
     */
    private function loadHasManyThrough(array $entities, RelationMetadata $relation, ?FetchPlan $nestedPlan): void
    {
        if ($relation->throughEntity === null || $relation->throughForeignKey === null || $relation->throughLocalKey === null) {
            return;
        }

        $localKeys = $this->extractPrimaryKeys($entities);
        if ($localKeys === []) {
            return;
        }

        // Step 1: Load intermediate entities that belong to our parent entities
        $throughMetadata = $this->metadataRegistry->get($relation->throughEntity);
        $throughBuilder = new SelectBuilder($this->connection);
        $throughBuilder->forEntity($relation->throughEntity, $throughMetadata, $this->hydrator);
        $throughBuilder->whereIn($relation->throughForeignKey, $localKeys);

        /** @var list<object> $intermediates */
        $intermediates = $throughBuilder->getEntities();

        if ($intermediates === []) {
            foreach ($entities as $entity) {
                $relProp = new ReflectionProperty($entity, $relation->propertyName);
                $relProp->setValue($entity, []);
            }

            return;
        }

        // Build intermediate ID → parent key map
        /** @var array<string, string> $intermediateToParent */
        $intermediateToParent = [];
        $intermediateIds = [];
        foreach ($intermediates as $intermediate) {
            $fkCol = $throughMetadata->columnByName($relation->throughForeignKey);
            $pkProp = new ReflectionProperty($intermediate, $throughMetadata->primaryKey->propertyName);
            $intId = self::str($pkProp->getValue($intermediate));
            $intermediateIds[] = $intId;

            if ($fkCol !== null) {
                $fkProp = new ReflectionProperty($intermediate, $fkCol->propertyName);
                $intermediateToParent[$intId] = self::str($fkProp->getValue($intermediate));
            }
        }

        // Step 2: Load target entities that reference the intermediates
        $targetMetadata = $this->metadataRegistry->get($relation->targetEntity);
        $targetBuilder = new SelectBuilder($this->connection);
        $targetBuilder->forEntity($relation->targetEntity, $targetMetadata, $this->hydrator);
        $targetBuilder->whereIn($relation->throughLocalKey, array_values(array_unique($intermediateIds)));

        /** @var list<object> $related */
        $related = $targetBuilder->getEntities();

        // Group target entities by parent key (via intermediate)
        /** @var array<string, list<object>> $grouped */
        $grouped = [];
        foreach ($related as $relatedEntity) {
            $throughCol = $targetMetadata->columnByName($relation->throughLocalKey);
            if ($throughCol !== null) {
                $prop = new ReflectionProperty($relatedEntity, $throughCol->propertyName);
                $throughId = self::str($prop->getValue($relatedEntity));
                $parentKey = $intermediateToParent[$throughId] ?? '';
                if ($parentKey !== '') {
                    $grouped[$parentKey][] = $relatedEntity;
                }
            }
        }

        // Assign to parent entities
        $parentMetadata = $this->metadataRegistry->get($entities[0]::class);
        foreach ($entities as $entity) {
            $pkProp = new ReflectionProperty($entity, $parentMetadata->primaryKey->propertyName);
            $pkValue = self::str($pkProp->getValue($entity));
            $relProp = new ReflectionProperty($entity, $relation->propertyName);
            $relProp->setValue($entity, $grouped[$pkValue] ?? []);
        }

        if ($nestedPlan !== null && count($related) > 0) {
            $this->loadRelations($related, $nestedPlan);
        }
    }

    /**
     * Load a MorphToMany (many-to-many polymorphic) relation.
     *
     * Uses a pivot table with type + ID columns for polymorphic M:N.
     * E.g., Post/Video → taggables → Tags (morphed by taggable_type + taggable_id).
     *
     * @param list<object> $entities
     */
    private function loadMorphToMany(array $entities, RelationMetadata $relation, ?FetchPlan $nestedPlan): void
    {
        if ($relation->pivotTable === null || $relation->morphTypeColumn === null
            || $relation->morphIdColumn === null || $relation->pivotRelatedKey === null) {
            return;
        }

        $localKeys = $this->extractPrimaryKeys($entities);
        if ($localKeys === []) {
            return;
        }

        $parentClass = $entities[0]::class;

        // Step 1: Query pivot table for matching morph type + IDs
        $pivotBuilder = new SelectBuilder($this->connection);
        $pivotBuilder->from($relation->pivotTable);
        $pivotBuilder->where($relation->morphTypeColumn, $parentClass);
        $pivotBuilder->whereIn($relation->morphIdColumn, $localKeys);
        $pivotRows = $pivotBuilder->get();

        // Collect related IDs grouped by parent key
        /** @var array<string, list<string>> $pivotMap */
        $pivotMap = [];
        $allRelatedIds = [];
        foreach ($pivotRows->rows as $row) {
            $parentId = self::str($row->get($relation->morphIdColumn));
            $relatedId = self::str($row->get($relation->pivotRelatedKey));
            $pivotMap[$parentId][] = $relatedId;
            $allRelatedIds[] = $relatedId;
        }

        $allRelatedIds = array_values(array_unique($allRelatedIds));
        if ($allRelatedIds === []) {
            foreach ($entities as $entity) {
                $relProp = new ReflectionProperty($entity, $relation->propertyName);
                $relProp->setValue($entity, []);
            }

            return;
        }

        // Step 2: Load related entities
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
            $key = self::str($prop->getValue($relatedEntity));
            $relatedIndex[$key] = $relatedEntity;
        }

        // Assign to parent entities
        $parentMetadata = $this->metadataRegistry->get($parentClass);
        foreach ($entities as $entity) {
            $pkProp = new ReflectionProperty($entity, $parentMetadata->primaryKey->propertyName);
            $pkValue = self::str($pkProp->getValue($entity));
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
     * @return list<scalar>
     */
    private function extractPrimaryKeys(array $entities): array
    {
        if ($entities === []) {
            return [];
        }

        $metadata = $this->metadataRegistry->get($entities[0]::class);
        $pkProp = $metadata->primaryKey->propertyName;

        return array_values(array_unique(self::scalarKeys(array_map(
            static function (object $entity) use ($pkProp): mixed {
                return new ReflectionProperty($entity, $pkProp)->getValue($entity);
            },
            $entities,
        ))));
    }

    /**
     * @param list<object> $entities
     * @return list<scalar>
     */
    private function extractValues(array $entities, string $propertyName): array
    {
        return self::scalarKeys(array_map(
            static function (object $entity) use ($propertyName): mixed {
                return new ReflectionProperty($entity, $propertyName)->getValue($entity);
            },
            $entities,
        ));
    }

    /**
     * Narrow extracted relation-key values to scalars.
     *
     * Relation keys (primary/foreign keys) are always scalar at the database
     * boundary; any non-scalar reflection value is dropped so the remaining
     * keys are safely string-castable for {@see array_unique} and binding.
     *
     * @param list<mixed> $values
     * @return list<scalar>
     */
    private static function scalarKeys(array $values): array
    {
        return array_values(array_filter($values, static fn(mixed $value): bool => is_scalar($value)));
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
