<?php

declare(strict_types=1);

namespace Pulsar\Codegen\Schema;

use NoDiscard;
use Pulsar\Api\Api;

use function array_diff;
use function array_intersect;
use function array_keys;

/**
 * Diff engine comparing two schema snapshots.
 *
 * Detects added, removed, and modified entities. For modified entities,
 * detects column-level additions, removals, and modifications.
 * Produces a DiffResult with operations in dependency order.
 */
#[Api(since: '1.0.0')]
final readonly class SchemaDiff
{
    /**
     * Compare two snapshots and produce an ordered list of operations.
     */
    #[NoDiscard]
    public function diff(SchemaSnapshot $old, SchemaSnapshot $new): DiffResult
    {
        $operations = [];

        $oldTables = array_keys($old->entities);
        $newTables = array_keys($new->entities);

        $addedTables = array_diff($newTables, $oldTables);
        $removedTables = array_diff($oldTables, $newTables);
        $commonTables = array_intersect($oldTables, $newTables);

        // Create new tables first
        foreach ($addedTables as $table) {
            $entity = $new->entities[$table];
            $operations[] = new SchemaOperation(
                type: SchemaOperationType::CreateTable,
                table: $table,
                metadata: ['className' => $entity->className],
            );

            // Add columns for the new table
            foreach ($entity->properties as $property) {
                $operations[] = new SchemaOperation(
                    type: SchemaOperationType::AddColumn,
                    table: $table,
                    column: $property->columnName,
                    metadata: [
                        'phpType' => $property->phpType,
                        'columnType' => $property->columnType,
                        'nullable' => $property->nullable,
                        'isPrimaryKey' => $property->isPrimaryKey,
                    ],
                );
            }

            // Add foreign keys for new table relationships
            foreach ($entity->relationships as $relationship) {
                if ($relationship->type === RelationType::BelongsTo) {
                    $operations[] = new SchemaOperation(
                        type: SchemaOperationType::AddForeignKey,
                        table: $table,
                        column: $relationship->foreignKey,
                        metadata: [
                            'relatedEntity' => $relationship->relatedEntity,
                            'localKey' => $relationship->localKey,
                        ],
                    );
                }
            }
        }

        // Diff common tables
        foreach ($commonTables as $table) {
            $this->diffEntity(
                $old->entities[$table],
                $new->entities[$table],
                $table,
                $operations,
            );
        }

        // Drop removed tables last
        foreach ($removedTables as $table) {
            $entity = $old->entities[$table];

            // Drop foreign keys first
            foreach ($entity->relationships as $relationship) {
                if ($relationship->type === RelationType::BelongsTo) {
                    $operations[] = new SchemaOperation(
                        type: SchemaOperationType::DropForeignKey,
                        table: $table,
                        column: $relationship->foreignKey,
                    );
                }
            }

            $operations[] = new SchemaOperation(
                type: SchemaOperationType::DropTable,
                table: $table,
                metadata: ['className' => $entity->className],
            );
        }

        return new DiffResult($operations);
    }

    /**
     * Diff two versions of the same entity and append operations.
     *
     * @param list<SchemaOperation> $operations
     */
    private function diffEntity(
        EntityDefinition $old,
        EntityDefinition $new,
        string $table,
        array &$operations,
    ): void {
        $oldColumns = self::indexByColumn($old->properties);
        $newColumns = self::indexByColumn($new->properties);

        $oldColumnNames = array_keys($oldColumns);
        $newColumnNames = array_keys($newColumns);

        $addedColumns = array_diff($newColumnNames, $oldColumnNames);
        $removedColumns = array_diff($oldColumnNames, $newColumnNames);
        $commonColumns = array_intersect($oldColumnNames, $newColumnNames);

        // Add new columns
        foreach ($addedColumns as $column) {
            $prop = $newColumns[$column];
            $operations[] = new SchemaOperation(
                type: SchemaOperationType::AddColumn,
                table: $table,
                column: $column,
                metadata: [
                    'phpType' => $prop->phpType,
                    'columnType' => $prop->columnType,
                    'nullable' => $prop->nullable,
                ],
            );
        }

        // Detect modified columns
        foreach ($commonColumns as $column) {
            $oldProp = $oldColumns[$column];
            $newProp = $newColumns[$column];

            if ($this->isColumnModified($oldProp, $newProp)) {
                $operations[] = new SchemaOperation(
                    type: SchemaOperationType::ModifyColumn,
                    table: $table,
                    column: $column,
                    metadata: [
                        'oldType' => $oldProp->columnType,
                        'newType' => $newProp->columnType,
                        'oldNullable' => $oldProp->nullable,
                        'newNullable' => $newProp->nullable,
                    ],
                );
            }
        }

        // Drop removed columns
        foreach ($removedColumns as $column) {
            $operations[] = new SchemaOperation(
                type: SchemaOperationType::DropColumn,
                table: $table,
                column: $column,
            );
        }

        // Diff relationships (foreign keys)
        $this->diffRelationships($old, $new, $table, $operations);
    }

    /**
     * @param list<SchemaOperation> $operations
     */
    private function diffRelationships(
        EntityDefinition $old,
        EntityDefinition $new,
        string $table,
        array &$operations,
    ): void {
        $oldFks = self::indexRelationshipsByForeignKey($old->relationships);
        $newFks = self::indexRelationshipsByForeignKey($new->relationships);

        $oldFkNames = array_keys($oldFks);
        $newFkNames = array_keys($newFks);

        $addedFks = array_diff($newFkNames, $oldFkNames);
        $removedFks = array_diff($oldFkNames, $newFkNames);

        foreach ($addedFks as $fk) {
            $rel = $newFks[$fk];
            $operations[] = new SchemaOperation(
                type: SchemaOperationType::AddForeignKey,
                table: $table,
                column: $fk,
                metadata: [
                    'relatedEntity' => $rel->relatedEntity,
                    'localKey' => $rel->localKey,
                ],
            );
        }

        foreach ($removedFks as $fk) {
            $operations[] = new SchemaOperation(
                type: SchemaOperationType::DropForeignKey,
                table: $table,
                column: $fk,
            );
        }
    }

    private function isColumnModified(PropertyDefinition $old, PropertyDefinition $new): bool
    {
        return $old->columnType !== $new->columnType
            || $old->nullable !== $new->nullable
            || $old->hasDefault !== $new->hasDefault
            || $old->defaultValue !== $new->defaultValue;
    }

    /**
     * Index properties by their column name.
     *
     * @param list<PropertyDefinition> $properties
     * @return array<string, PropertyDefinition>
     */
    private static function indexByColumn(array $properties): array
    {
        $indexed = [];

        foreach ($properties as $property) {
            $indexed[$property->columnName] = $property;
        }

        return $indexed;
    }

    /**
     * Index BelongsTo relationships by their foreign key.
     *
     * @param list<RelationshipDefinition> $relationships
     * @return array<string, RelationshipDefinition>
     */
    private static function indexRelationshipsByForeignKey(array $relationships): array
    {
        $indexed = [];

        foreach ($relationships as $relationship) {
            if ($relationship->type === RelationType::BelongsTo) {
                $indexed[$relationship->foreignKey] = $relationship;
            }
        }

        return $indexed;
    }
}
