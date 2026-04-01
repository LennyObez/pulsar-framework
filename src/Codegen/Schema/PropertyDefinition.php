<?php

declare(strict_types=1);

namespace Pulsar\Codegen\Schema;

use NoDiscard;
use Pulsar\Api\Api;

use function array_key_exists;
use function is_string;
use function preg_match;
use function str_contains;
use function str_starts_with;
use function strtolower;

/**
 * Metadata for a single entity property used during code generation.
 *
 * @phpstan-type PropertyArray array{
 *     name: string,
 *     phpType: string,
 *     columnName: string,
 *     columnType: string,
 *     nullable: bool,
 *     hasDefault: bool,
 *     defaultValue: mixed,
 *     validationRules: list<string>,
 *     isFilterable: bool,
 *     isSortable: bool,
 *     length: int|null,
 *     isPrimaryKey: bool,
 * }
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PropertyDefinition
{
    /**
     * @param list<string> $validationRules
     */
    public function __construct(
        public string $name,
        public string $phpType,
        public string $columnName,
        public string $columnType,
        public bool $nullable,
        public bool $hasDefault,
        public mixed $defaultValue,
        public array $validationRules,
        public bool $isFilterable,
        public bool $isSortable,
        public ?int $length,
        public bool $isPrimaryKey,
    ) {}

    /**
     * Map a database column type string to a PHP type string.
     */
    #[NoDiscard]
    public static function mapColumnTypeToPhp(string $columnType): string
    {
        $normalized = strtolower($columnType);

        // Boolean types
        if ($normalized === 'bool' || $normalized === 'boolean' || $normalized === 'tinyint(1)') {
            return 'bool';
        }

        // Integer types
        if ($normalized === 'int' || $normalized === 'integer' || $normalized === 'bigint'
            || $normalized === 'smallint' || $normalized === 'mediumint' || $normalized === 'tinyint') {
            return 'int';
        }

        // Float types
        if ($normalized === 'decimal' || $normalized === 'float' || $normalized === 'double'
            || $normalized === 'real' || $normalized === 'numeric') {
            return 'float';
        }

        // DateTime types
        if ($normalized === 'datetime' || $normalized === 'timestamp'
            || str_starts_with($normalized, 'timestamp')) {
            return '\\DateTimeImmutable';
        }

        if ($normalized === 'date') {
            return '\\DateTimeImmutable';
        }

        // JSON type
        if ($normalized === 'json' || $normalized === 'jsonb') {
            return 'array';
        }

        // String types (varchar, text, char, uuid, etc.)
        if ($normalized === 'varchar' || str_starts_with($normalized, 'varchar(')
            || $normalized === 'text' || $normalized === 'mediumtext' || $normalized === 'longtext'
            || $normalized === 'char' || str_starts_with($normalized, 'char(')
            || $normalized === 'uuid' || $normalized === 'string'
            || str_starts_with($normalized, 'character varying')) {
            return 'string';
        }

        // Binary types
        if ($normalized === 'blob' || $normalized === 'binary' || $normalized === 'varbinary') {
            return 'string';
        }

        return 'mixed';
    }

    /**
     * Extract length from a column type like `varchar(255)`.
     */
    #[NoDiscard]
    public static function extractLength(string $columnType): ?int
    {
        if (preg_match('/\((\d+)\)/', $columnType, $matches) === 1) {
            // Don't return length for tinyint(1): that's a boolean indicator
            if (strtolower($columnType) === 'tinyint(1)') {
                return null;
            }

            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Infer basic validation rules from column metadata.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public static function inferValidationRules(string $columnType, bool $nullable, ?int $length): array
    {
        $rules = [];

        if (! $nullable) {
            $rules[] = 'required';
        }

        $phpType = self::mapColumnTypeToPhp($columnType);

        if ($phpType === 'string' && $length !== null) {
            $rules[] = 'max:' . $length;
        }

        if ($phpType === 'int') {
            $rules[] = 'integer';
        }

        if ($phpType === 'float') {
            $rules[] = 'numeric';
        }

        if (str_contains(strtolower($columnType), 'email')) {
            $rules[] = 'email';
        }

        return $rules;
    }

    /**
     * @return PropertyArray
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'phpType' => $this->phpType,
            'columnName' => $this->columnName,
            'columnType' => $this->columnType,
            'nullable' => $this->nullable,
            'hasDefault' => $this->hasDefault,
            'defaultValue' => $this->defaultValue,
            'validationRules' => $this->validationRules,
            'isFilterable' => $this->isFilterable,
            'isSortable' => $this->isSortable,
            'length' => $this->length,
            'isPrimaryKey' => $this->isPrimaryKey,
        ];
    }

    /**
     * @param array{
     *     name?: string,
     *     phpType?: string,
     *     columnName?: string,
     *     columnType?: string,
     *     nullable?: bool,
     *     hasDefault?: bool,
     *     defaultValue?: mixed,
     *     validationRules?: list<string>,
     *     isFilterable?: bool,
     *     isSortable?: bool,
     *     length?: int|null,
     *     isPrimaryKey?: bool,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? '',
            phpType: $data['phpType'] ?? 'mixed',
            columnName: $data['columnName'] ?? '',
            columnType: $data['columnType'] ?? '',
            nullable: $data['nullable'] ?? false,
            hasDefault: $data['hasDefault'] ?? false,
            defaultValue: array_key_exists('defaultValue', $data) ? $data['defaultValue'] : null,
            validationRules: self::filterStringList($data['validationRules'] ?? []),
            isFilterable: $data['isFilterable'] ?? false,
            isSortable: $data['isSortable'] ?? false,
            length: $data['length'] ?? null,
            isPrimaryKey: $data['isPrimaryKey'] ?? false,
        );
    }

    /**
     * @param list<string> $items
     * @return list<string>
     */
    private static function filterStringList(array $items): array
    {
        $strings = [];

        foreach ($items as $item) {
            if (is_string($item)) {
                $strings[] = $item;
            }
        }

        return $strings;
    }
}
