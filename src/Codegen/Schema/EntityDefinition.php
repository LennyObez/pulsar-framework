<?php

declare(strict_types=1);

namespace Pulsar\Codegen\Schema;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Database\Introspection\ColumnInfo;

use function array_map;
use function in_array;
use function ksort;

/**
 * Central model representing an entity for code generation.
 *
 * Built from database introspection (legacy import) or entity mapping metadata (domain-first).
 * Carries all information needed by generators: properties, relationships, conventions.
 *
 * @phpstan-type EntityArray array{
 *     className: string,
 *     namespace: string,
 *     tableName: string,
 *     properties: list<array<string, mixed>>,
 *     relationships: list<array<string, mixed>>,
 *     primaryKey: string,
 *     hasTimestamps: bool,
 *     hasSoftDeletes: bool,
 *     isAuditAware: bool,
 * }
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class EntityDefinition
{
    /**
     * Conventional timestamp column names.
     *
     * @var list<string>
     */
    private const array TIMESTAMP_COLUMNS = ['created_at', 'updated_at'];

    /**
     * Conventional soft-delete column names.
     *
     * @var list<string>
     */
    private const array SOFT_DELETE_COLUMNS = ['deleted_at'];

    /**
     * Conventional audit column names.
     *
     * @var list<string>
     */
    private const array AUDIT_COLUMNS = ['created_by', 'updated_by'];

    /**
     * @param list<PropertyDefinition> $properties
     * @param list<RelationshipDefinition> $relationships
     */
    public function __construct(
        public string $className,
        public string $namespace,
        public string $tableName,
        public array $properties,
        public array $relationships,
        public string $primaryKey,
        public bool $hasTimestamps,
        public bool $hasSoftDeletes,
        public bool $isAuditAware,
    ) {}

    /**
     * Build an EntityDefinition from database column introspection data.
     *
     * Uses IdentifierNormalizer to derive PHP names from database identifiers.
     * Detects timestamp, soft-delete, and audit columns by convention.
     *
     * @param list<ColumnInfo> $columns
     */
    #[NoDiscard]
    public static function fromDatabaseColumns(
        string $tableName,
        array $columns,
        string $primaryKey,
        string $namespace = 'App\\Entity',
    ): self {
        $columnNames = array_map(
            static fn(ColumnInfo $col): string => $col->name,
            $columns,
        );

        $hasTimestamps = self::hasAllColumns($columnNames, self::TIMESTAMP_COLUMNS);
        $hasSoftDeletes = self::hasAllColumns($columnNames, self::SOFT_DELETE_COLUMNS);
        $isAuditAware = self::hasAllColumns($columnNames, self::AUDIT_COLUMNS);

        $properties = array_map(
            static function (ColumnInfo $col): PropertyDefinition {
                $phpType = PropertyDefinition::mapColumnTypeToPhp($col->type);
                $length = PropertyDefinition::extractLength($col->type);
                $validationRules = PropertyDefinition::inferValidationRules(
                    $col->type,
                    $col->nullable,
                    $length,
                );

                return new PropertyDefinition(
                    name: IdentifierNormalizer::toPropertyName($col->name),
                    phpType: $phpType,
                    columnName: $col->name,
                    columnType: $col->type,
                    nullable: $col->nullable,
                    hasDefault: $col->default !== null,
                    defaultValue: $col->default,
                    validationRules: $validationRules,
                    isFilterable: true,
                    isSortable: true,
                    length: $length,
                    isPrimaryKey: $col->isPrimaryKey,
                );
            },
            $columns,
        );

        return new self(
            className: IdentifierNormalizer::toClassName($tableName),
            namespace: $namespace,
            tableName: $tableName,
            properties: $properties,
            relationships: [],
            primaryKey: $primaryKey,
            hasTimestamps: $hasTimestamps,
            hasSoftDeletes: $hasSoftDeletes,
            isAuditAware: $isAuditAware,
        );
    }

    /**
     * @return EntityArray
     */
    #[NoDiscard]
    public function toArray(): array
    {
        $properties = array_map(
            static fn(PropertyDefinition $p): array => $p->toArray(),
            $this->properties,
        );

        $relationships = array_map(
            static fn(RelationshipDefinition $r): array => $r->toArray(),
            $this->relationships,
        );

        $result = [
            'className' => $this->className,
            'namespace' => $this->namespace,
            'tableName' => $this->tableName,
            'properties' => $properties,
            'relationships' => $relationships,
            'primaryKey' => $this->primaryKey,
            'hasTimestamps' => $this->hasTimestamps,
            'hasSoftDeletes' => $this->hasSoftDeletes,
            'isAuditAware' => $this->isAuditAware,
        ];

        ksort($result);

        return $result;
    }

    /**
     * @param array{
     *     className?: string,
     *     namespace?: string,
     *     tableName?: string,
     *     properties?: list<array<string, mixed>>,
     *     relationships?: list<array<string, mixed>>,
     *     primaryKey?: string,
     *     hasTimestamps?: bool,
     *     hasSoftDeletes?: bool,
     *     isAuditAware?: bool,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $properties = [];
        foreach ($data['properties'] ?? [] as $prop) {
            $properties[] = PropertyDefinition::fromArray($prop);
        }

        $relationships = [];
        foreach ($data['relationships'] ?? [] as $rel) {
            $relationships[] = RelationshipDefinition::fromArray($rel);
        }

        return new self(
            className: $data['className'] ?? '',
            namespace: $data['namespace'] ?? '',
            tableName: $data['tableName'] ?? '',
            properties: $properties,
            relationships: $relationships,
            primaryKey: $data['primaryKey'] ?? 'id',
            hasTimestamps: $data['hasTimestamps'] ?? false,
            hasSoftDeletes: $data['hasSoftDeletes'] ?? false,
            isAuditAware: $data['isAuditAware'] ?? false,
        );
    }

    /**
     * Check if all target column names exist in the provided list.
     *
     * @param list<string> $columnNames
     * @param list<string> $targetColumns
     */
    private static function hasAllColumns(array $columnNames, array $targetColumns): bool
    {
        return array_all($targetColumns, static fn(string $target): bool => in_array($target, $columnNames, true));
    }
}
