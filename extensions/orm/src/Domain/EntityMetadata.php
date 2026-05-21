<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Domain;

use Pulsar\Api\Api;

/**
 * Complete metadata for a mapped entity class.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class EntityMetadata
{
    /**
     * @param class-string $entityClass Fully-qualified entity class name
     * @param string $tableName Database table name
     * @param string|null $schema Database schema (if specified)
     * @param ColumnMetadata $primaryKey Primary key column metadata
     * @param array<string, ColumnMetadata> $columns Map of property name => column metadata
     * @param array<string, RelationMetadata> $relations Map of property name => relation metadata
     * @param bool $hasTimestamps Whether the entity has automatic timestamps
     * @param string|null $createdAtColumn Created-at column name
     * @param string|null $updatedAtColumn Updated-at column name
     * @param bool $hasSoftDelete Whether the entity supports soft delete
     * @param string|null $softDeleteColumn Soft delete column name
     * @param bool $isTenantScoped Whether the entity is tenant-scoped
     * @param string|null $tenantColumn Tenant column name
     * @param bool $isTenantShared Whether the entity is shared across tenants
     * @param string|null $versionProperty Version property name (for optimistic locking)
     * @param list<string> $encryptedColumns List of encrypted column property names
     */
    public function __construct(
        public string $entityClass,
        public string $tableName,
        public ?string $schema,
        public ColumnMetadata $primaryKey,
        public array $columns,
        public array $relations,
        public bool $hasTimestamps,
        public ?string $createdAtColumn,
        public ?string $updatedAtColumn,
        public bool $hasSoftDelete,
        public ?string $softDeleteColumn,
        public bool $isTenantScoped,
        public ?string $tenantColumn,
        public bool $isTenantShared,
        public ?string $versionProperty,
        public array $encryptedColumns,
    ) {}

    /**
     * Get column metadata by property name.
     */
    public function columnByProperty(string $property): ?ColumnMetadata
    {
        return $this->columns[$property] ?? null;
    }

    /**
     * Get column metadata by database column name.
     */
    public function columnByName(string $columnName): ?ColumnMetadata
    {
        /** @var ColumnMetadata|null $found */
        $found = array_find(
            $this->columns,
            static fn(ColumnMetadata $col): bool => $col->columnName === $columnName,
        );

        return $found;
    }

    /**
     * Get all insertable columns.
     *
     * @return array<string, ColumnMetadata>
     */
    public function insertableColumns(): array
    {
        return array_filter(
            $this->columns,
            static fn(ColumnMetadata $col): bool => $col->insertable && !($col->isPrimaryKey && $col->autoIncrement),
        );
    }

    /**
     * Get all updatable columns (excluding PK and version).
     *
     * @return array<string, ColumnMetadata>
     */
    public function updatableColumns(): array
    {
        return array_filter(
            $this->columns,
            static fn(ColumnMetadata $col): bool => $col->updatable && !$col->isPrimaryKey && !$col->isVersion,
        );
    }

    /**
     * Get the qualified table reference (schema.table or just table).
     */
    public function qualifiedTableName(): string
    {
        if ($this->schema !== null) {
            return $this->schema . '.' . $this->tableName;
        }

        return $this->tableName;
    }
}
