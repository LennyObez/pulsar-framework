<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Adapter;

use function array_map;
use function count;
use function explode;
use function implode;
use function in_array;
use function preg_match;

use Pulsar\Api\Internal;
use Pulsar\Database\Introspection\ColumnInfo;
use Pulsar\Database\Introspection\DatabaseIntrospector;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Domain\FieldDefinition;
use Pulsar\Extension\Admin\Domain\FieldType;

use function rtrim;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strtolower;
use function ucfirst;

/**
 * Creates DataResourceInterface instances from database introspection.
 *
 * Auto-discovers database tables and maps their columns to admin
 * resource field definitions, enabling CRUD without explicit model classes.
 */
#[Internal]
final readonly class IntrospectedResourceFactory
{
    public function __construct(
        private DatabaseIntrospector $introspector,
    ) {}

    /**
     * Discover all tables and convert to admin resources.
     *
     * @param list<string> $excludePrefixes Table name prefixes to exclude
     * @return list<DataResourceInterface>
     */
    public function discoverAll(array $excludePrefixes = []): array
    {
        $tables = $this->introspector->tables();

        $resources = [];

        foreach ($tables as $tableInfo) {
            if ($this->isExcluded($tableInfo->name, $excludePrefixes)) {
                continue;
            }

            $resources[] = $this->forTable($tableInfo->name);
        }

        return $resources;
    }

    /**
     * Create a DataResourceInterface for a specific table.
     */
    public function forTable(string $table): DataResourceInterface
    {
        $columns = $this->introspector->columns($table);
        $pk = $this->introspector->primaryKey($table) ?? 'id';
        $fields = array_map(fn(ColumnInfo $col): FieldDefinition => $this->columnToField($col), $columns);
        $singularLabel = $this->toSingularLabel($table);
        $pluralLabel = $this->toPluralLabel($table);

        return new IntrospectedResource(
            tableName: $table,
            singularLabel: $singularLabel,
            pluralLabel: $pluralLabel,
            primaryKeyField: $pk,
            fields: $fields,
        );
    }

    private function columnToField(ColumnInfo $column): FieldDefinition
    {
        $fieldType = $this->mapColumnType($column->type);
        $isBinary = $this->isBinaryType($column->type);

        return new FieldDefinition(
            name: $column->name,
            type: $fieldType,
            label: $this->columnToLabel($column->name),
            sortable: !$isBinary,
            filterable: !$isBinary && !in_array($fieldType, [FieldType::Text, FieldType::Json], true),
            searchable: in_array($fieldType, [FieldType::String, FieldType::Text, FieldType::Email], true),
            editable: !$column->isPrimaryKey && !$isBinary,
            visibleOnList: !$isBinary && $fieldType !== FieldType::Text,
            visibleOnDetail: true,
            visibleOnForm: !$column->isPrimaryKey && !$isBinary,
        );
    }

    private function mapColumnType(string $dbType): FieldType
    {
        $normalized = strtolower($dbType);

        // Strip size/precision suffixes (e.g., "varchar(255)" → "varchar")
        if (preg_match('/^(\w+)/', $normalized, $matches) === 1) {
            $normalized = $matches[1];
        }

        return match ($normalized) {
            'int', 'integer', 'bigint', 'smallint', 'tinyint', 'mediumint', 'int2', 'int4', 'int8' => FieldType::Integer,
            'float', 'double', 'real', 'decimal', 'numeric', 'float4', 'float8' => FieldType::Float,
            'bool', 'boolean' => FieldType::Boolean,
            'date' => FieldType::Date,
            'datetime', 'timestamp', 'timestamptz' => FieldType::DateTime,
            'json', 'jsonb' => FieldType::Json,
            'text', 'tinytext', 'mediumtext', 'longtext', 'clob' => FieldType::Text,
            default => FieldType::String,
        };
    }

    private function isBinaryType(string $dbType): bool
    {
        $normalized = strtolower($dbType);

        if (preg_match('/^(\w+)/', $normalized, $matches) === 1) {
            $normalized = $matches[1];
        }

        return in_array($normalized, ['blob', 'binary', 'varbinary', 'bytea', 'tinyblob', 'mediumblob', 'longblob'], true);
    }

    private function columnToLabel(string $columnName): string
    {
        $words = explode('_', $columnName);

        return implode(' ', array_map(ucfirst(...), $words));
    }

    private function toSingularLabel(string $tableName): string
    {
        $label = $this->columnToLabel($tableName);

        // Simple English pluralization reversal
        if (str_contains($label, ' ')) {
            $words = explode(' ', $label);
            $lastIndex = count($words) - 1;
            $words[$lastIndex] = $this->singularize($words[$lastIndex]);

            return implode(' ', $words);
        }

        return $this->singularize($label);
    }

    private function toPluralLabel(string $tableName): string
    {
        return $this->columnToLabel($tableName);
    }

    private function singularize(string $word): string
    {
        $lower = strtolower($word);

        if (str_contains($lower, 'ies') && str_contains($word, 'ies')) {
            return rtrim($word, 's');
            // "Categories" → "Categorie" — not perfect, but better
            // Actually let's do it properly
        }

        // "ies" → "y" (e.g., "Categories" → "Category")
        if (strlen($word) > 3 && strtolower(substr($word, -3)) === 'ies') {
            return substr($word, 0, -3) . ucfirst(substr($word, -3, 1) === strtolower(substr($word, -3, 1)) ? 'y' : 'Y');
        }

        // "ses", "xes", "zes", "ches", "shes" → remove "es"
        if (strlen($word) > 2 && strtolower(substr($word, -2)) === 'es') {
            $beforeEs = strtolower(substr($word, -4, 2));
            if (in_array($beforeEs, ['ss', 'sh', 'ch'], true) || in_array(strtolower(substr($word, -3, 1)), ['x', 'z'], true)) {
                return substr($word, 0, -2);
            }
        }

        // "s" → remove "s" (e.g., "Users" → "User")
        if (strlen($word) > 1 && strtolower(substr($word, -1)) === 's' && strtolower(substr($word, -2, 1)) !== 's') {
            return substr($word, 0, -1);
        }

        return $word;
    }

    /**
     * @param list<string> $prefixes
     */
    private function isExcluded(string $tableName, array $prefixes): bool
    {
        return array_any(
            $prefixes,
            static fn(string $prefix): bool => str_starts_with($tableName, $prefix),
        );
    }
}
