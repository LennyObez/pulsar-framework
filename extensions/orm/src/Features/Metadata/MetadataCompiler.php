<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Metadata;

use Pulsar\Api\Internal;
use Pulsar\Extension\Orm\Attribute\BlindIndex;
use Pulsar\Extension\Orm\Attribute\CastUsing;
use Pulsar\Extension\Orm\Attribute\Column;
use Pulsar\Extension\Orm\Attribute\Encrypted;
use Pulsar\Extension\Orm\Attribute\Id;
use Pulsar\Extension\Orm\Attribute\Relation;
use Pulsar\Extension\Orm\Attribute\SoftDelete;
use Pulsar\Extension\Orm\Attribute\Table;
use Pulsar\Extension\Orm\Attribute\TenantScoped;
use Pulsar\Extension\Orm\Attribute\TenantShared;
use Pulsar\Extension\Orm\Attribute\Timestamps;
use Pulsar\Extension\Orm\Attribute\Version;
use Pulsar\Extension\Orm\Config\OrmConfig;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Domain\RelationMetadata;
use Pulsar\Extension\Orm\Exception\MappingException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionProperty;

use function strtolower;

/**
 * Compiles entity metadata from PHP attributes via reflection.
 */
#[Internal]
final readonly class MetadataCompiler
{
    public function __construct(
        private readonly OrmConfig $config,
    ) {}

    /**
     * Compile metadata for the given entity class.
     *
     * @param class-string $entityClass
     * @throws MappingException
     */
    public function compile(string $entityClass): EntityMetadata
    {
        $reflection = new ReflectionClass($entityClass);

        // Table attribute
        $tableAttrs = $reflection->getAttributes(Table::class, ReflectionAttribute::IS_INSTANCEOF);
        if ($tableAttrs === []) {
            throw MappingException::missingTable($entityClass);
        }
        /** @var Table $table */
        $table = $tableAttrs[0]->newInstance();

        // Timestamps
        $timestampAttrs = $reflection->getAttributes(Timestamps::class, ReflectionAttribute::IS_INSTANCEOF);
        $hasTimestamps = $timestampAttrs !== [];
        $createdAtColumn = null;
        $updatedAtColumn = null;
        if ($hasTimestamps) {
            /** @var Timestamps $timestamps */
            $timestamps = $timestampAttrs[0]->newInstance();
            $createdAtColumn = $timestamps->createdAt;
            $updatedAtColumn = $timestamps->updatedAt;
        }

        // Soft delete
        $softDeleteAttrs = $reflection->getAttributes(SoftDelete::class, ReflectionAttribute::IS_INSTANCEOF);
        $hasSoftDelete = $softDeleteAttrs !== [];
        $softDeleteColumn = null;
        if ($hasSoftDelete) {
            /** @var SoftDelete $softDelete */
            $softDelete = $softDeleteAttrs[0]->newInstance();
            $softDeleteColumn = $softDelete->column;
        }

        // Tenant scoping
        $tenantScopedAttrs = $reflection->getAttributes(TenantScoped::class, ReflectionAttribute::IS_INSTANCEOF);
        $isTenantScoped = $tenantScopedAttrs !== [];
        $tenantColumn = null;
        if ($isTenantScoped) {
            /** @var TenantScoped $tenantScoped */
            $tenantScoped = $tenantScopedAttrs[0]->newInstance();
            $tenantColumn = $tenantScoped->column ?? $this->config->tenantColumn;
        }

        $tenantSharedAttrs = $reflection->getAttributes(TenantShared::class, ReflectionAttribute::IS_INSTANCEOF);
        $isTenantShared = $tenantSharedAttrs !== [];

        // Process properties
        $columns = [];
        $relations = [];
        $primaryKey = null;
        $versionProperty = null;
        /** @var list<string> $encryptedColumns */
        $encryptedColumns = [];

        foreach ($reflection->getProperties() as $property) {
            $columnMeta = $this->compileColumn($property, $entityClass);
            if ($columnMeta !== null) {
                $columns[$property->getName()] = $columnMeta;

                if ($columnMeta->isPrimaryKey) {
                    $primaryKey = $columnMeta;
                }

                if ($columnMeta->isVersion) {
                    $versionProperty = $property->getName();
                }

                if ($columnMeta->encrypted) {
                    $encryptedColumns[] = $property->getName();
                }

                continue;
            }

            $relationMeta = $this->compileRelation($property, $entityClass);
            if ($relationMeta !== null) {
                $relations[$property->getName()] = $relationMeta;
            }
        }

        if ($primaryKey === null) {
            throw MappingException::missingId($entityClass);
        }

        return new EntityMetadata(
            entityClass: $entityClass,
            tableName: $table->name,
            schema: $table->schema,
            primaryKey: $primaryKey,
            columns: $columns,
            relations: $relations,
            hasTimestamps: $hasTimestamps,
            createdAtColumn: $createdAtColumn,
            updatedAtColumn: $updatedAtColumn,
            hasSoftDelete: $hasSoftDelete,
            softDeleteColumn: $softDeleteColumn,
            isTenantScoped: $isTenantScoped,
            tenantColumn: $tenantColumn,
            isTenantShared: $isTenantShared,
            versionProperty: $versionProperty,
            encryptedColumns: $encryptedColumns,
        );
    }

    /**
     * @param class-string $entityClass
     */
    private function compileColumn(ReflectionProperty $property, string $entityClass): ?ColumnMetadata
    {
        $columnAttrs = $property->getAttributes(Column::class, ReflectionAttribute::IS_INSTANCEOF);
        $idAttrs = $property->getAttributes(Id::class, ReflectionAttribute::IS_INSTANCEOF);
        $versionAttrs = $property->getAttributes(Version::class, ReflectionAttribute::IS_INSTANCEOF);

        // Property must have #[Column] or #[Id] to be a mapped column
        if ($columnAttrs === [] && $idAttrs === []) {
            return null;
        }

        $isPrimaryKey = $idAttrs !== [];
        $autoIncrement = false;
        if ($isPrimaryKey) {
            /** @var Id $id */
            $id = $idAttrs[0]->newInstance();
            $autoIncrement = $id->autoIncrement;
        }

        $isVersion = $versionAttrs !== [];

        // Column config (optional if only #[Id] is present)
        $type = ColumnType::String;
        $columnName = strtolower($property->getName());
        $nullable = false;
        $insertable = true;
        $updatable = true;

        if ($columnAttrs !== []) {
            /** @var Column $column */
            $column = $columnAttrs[0]->newInstance();
            $type = $column->type;
            $columnName = $column->name ?? strtolower($property->getName());
            $nullable = $column->nullable;
            $insertable = $column->insertable;
            $updatable = $column->updatable;
        } elseif ($isPrimaryKey) {
            $type = ColumnType::Integer;
            $columnName = 'id';
        }

        // Encryption
        $encryptedAttrs = $property->getAttributes(Encrypted::class, ReflectionAttribute::IS_INSTANCEOF);
        $encrypted = $encryptedAttrs !== [];

        // Blind index
        $blindIndexAttrs = $property->getAttributes(BlindIndex::class, ReflectionAttribute::IS_INSTANCEOF);
        $blindIndexColumn = null;
        $blindIndexHashLength = null;
        if ($blindIndexAttrs !== []) {
            /** @var BlindIndex $blindIndex */
            $blindIndex = $blindIndexAttrs[0]->newInstance();
            $blindIndexColumn = $blindIndex->column;
            $blindIndexHashLength = $blindIndex->hashLength;
        }

        // Custom caster
        $casterAttrs = $property->getAttributes(CastUsing::class, ReflectionAttribute::IS_INSTANCEOF);
        $casterClass = null;
        if ($casterAttrs !== []) {
            /** @var CastUsing $caster */
            $caster = $casterAttrs[0]->newInstance();
            $casterClass = $caster->casterClass;
        }

        return new ColumnMetadata(
            propertyName: $property->getName(),
            columnName: $columnName,
            type: $type,
            nullable: $nullable,
            isPrimaryKey: $isPrimaryKey,
            autoIncrement: $autoIncrement,
            isVersion: $isVersion,
            encrypted: $encrypted,
            blindIndexColumn: $blindIndexColumn,
            blindIndexHashLength: $blindIndexHashLength,
            insertable: $insertable,
            updatable: $updatable,
            casterClass: $casterClass,
        );
    }

    /**
     * @param class-string $entityClass
     */
    private function compileRelation(ReflectionProperty $property, string $entityClass): ?RelationMetadata
    {
        $attrs = $property->getAttributes(Relation::class, ReflectionAttribute::IS_INSTANCEOF);
        if ($attrs === []) {
            return null;
        }

        /** @var Relation $relation */
        $relation = $attrs[0]->newInstance();

        $foreignKey = $relation->foreignKey ?? $this->inferForeignKey($entityClass, $relation);
        $localKey = $relation->localKey ?? 'id';

        return new RelationMetadata(
            propertyName: $property->getName(),
            type: $relation->type,
            targetEntity: $relation->target,
            foreignKey: $foreignKey,
            localKey: $localKey,
            pivotTable: $relation->pivotTable,
            pivotForeignKey: $relation->pivotForeignKey,
            pivotRelatedKey: $relation->pivotRelatedKey,
        );
    }

    /**
     * @param class-string $entityClass
     */
    private function inferForeignKey(string $entityClass, Relation $relation): string
    {
        // For BelongsTo, infer from the target class name
        // For HasOne/HasMany, infer from the owning class name
        $ref = new ReflectionClass($entityClass);
        $shortName = strtolower($ref->getShortName());

        return match ($relation->type) {
            \Pulsar\Extension\Orm\Domain\RelationType::BelongsTo => strtolower(new ReflectionClass($relation->target)->getShortName()) . '_id',
            default => $shortName . '_id',
        };
    }
}
