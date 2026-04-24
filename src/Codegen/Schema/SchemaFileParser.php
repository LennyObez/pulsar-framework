<?php

declare(strict_types=1);

namespace Pulsar\Codegen\Schema;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function file_get_contents;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function realpath;
use function sprintf;
use function str_ends_with;
use function str_starts_with;

use const JSON_THROW_ON_ERROR;

/**
 * Parses Pulsar schema files (.pulsar.json) for entity generation.
 *
 * Schema files define entities in a declarative, Prisma-inspired format.
 * The parser converts them into EntityDefinition objects for code generation.
 *
 * Schema file format:
 *   {
 *     "entities": {
 *       "User": {
 *         "table": "users",
 *         "properties": {
 *           "name": { "type": "string", "length": 255 },
 *           "email": { "type": "string", "unique": true },
 *           "role": { "type": "enum", "values": ["admin", "user"] }
 *         },
 *         "relations": {
 *           "posts": { "type": "hasMany", "target": "Post" }
 *         },
 *         "timestamps": true,
 *         "softDeletes": true
 *       }
 *     }
 *   }
 */
#[Api(since: '1.0.0')]
final readonly class SchemaFileParser
{
    /**
     * F387.8 / ADR-0028: codegen takes external schema files as
     * input. A schema source compromised by a malicious or
     * accidental write becomes a supply-chain risk: the generator
     * runs the parser at build time, the parser influences what
     * gets emitted, and the emitted code lands inside the repo.
     * Constraining the schema-file extension and the path prefix
     * limits the blast radius — codegen no longer reads from
     * arbitrary disk locations or arbitrary file types.
     */
    private const string ALLOWED_SUFFIX = '.pulsar.json';

    /**
     * @param string $defaultNamespace Namespace used when a schema file omits the `namespace` key.
     * @param string|null $allowedRoot Project-root prefix that schema paths must resolve under.
     *                                 Pass `null` only in trusted unit tests.
     */
    public function __construct(
        private string $defaultNamespace = 'App\\Entity',
        private ?string $allowedRoot = null,
    ) {}

    /**
     * Parse a schema file into entity definitions.
     *
     * @return list<EntityDefinition>
     *
     * @throws RuntimeException If the file cannot be read, parsed,
     *                          or violates the source-allowlist.
     */
    #[NoDiscard]
    public function parseFile(string $path): array
    {
        $this->assertAllowedSource($path);

        $content = file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException(sprintf('Cannot read schema file: %s', $path));
        }

        return $this->parseJson($content);
    }

    /**
     * Reject schema paths that:
     *   - do not end in `.pulsar.json` (limits the input format);
     *   - resolve outside the configured project root (limits the
     *     directories the parser is willing to read from).
     */
    private function assertAllowedSource(string $path): void
    {
        if (!str_ends_with($path, self::ALLOWED_SUFFIX)) {
            throw new RuntimeException(sprintf(
                'Schema file rejected: only %s files are accepted (got "%s")',
                self::ALLOWED_SUFFIX,
                $path,
            ));
        }

        if ($this->allowedRoot === null) {
            return;
        }

        $realPath = realpath($path);
        $realRoot = realpath($this->allowedRoot);

        if ($realPath === false || $realRoot === false) {
            throw new RuntimeException(sprintf(
                'Schema file rejected: path does not exist or is unreadable ("%s")',
                $path,
            ));
        }

        $normalizedPath = str_replace('\\', '/', $realPath);
        $normalizedRoot = str_replace('\\', '/', $realRoot);

        if (!str_starts_with($normalizedPath, $normalizedRoot)) {
            throw new RuntimeException(sprintf(
                'Schema file rejected: "%s" is outside the allowed root "%s"',
                $path,
                $this->allowedRoot,
            ));
        }
    }

    /**
     * Parse JSON schema content into entity definitions.
     *
     * @return list<EntityDefinition>
     */
    #[NoDiscard]
    public function parseJson(string $json): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $entities = [];
        $rawEntities = is_array($data['entities'] ?? null) ? $data['entities'] : [];
        $namespace = is_string($data['namespace'] ?? null) ? $data['namespace'] : $this->defaultNamespace;

        foreach ($rawEntities as $className => $definition) {
            if (!is_string($className) || !is_array($definition)) {
                continue;
            }

            $entities[] = $this->parseEntity($className, $definition, $namespace);
        }

        return $entities;
    }

    /**
     * @param array<mixed, mixed> $definition
     */
    private function parseEntity(string $className, array $definition, string $namespace): EntityDefinition
    {
        $tableName = is_string($definition['table'] ?? null)
            ? $definition['table']
            : IdentifierNormalizer::toTableName($className);

        $properties = [];
        $rawProperties = is_array($definition['properties'] ?? null) ? $definition['properties'] : [];

        foreach ($rawProperties as $propName => $propDef) {
            if (!is_string($propName) || !is_array($propDef)) {
                continue;
            }

            $properties[] = $this->parseProperty($propName, $propDef);
        }

        $relationships = [];
        $rawRelations = is_array($definition['relations'] ?? null) ? $definition['relations'] : [];

        foreach ($rawRelations as $relName => $relDef) {
            if (!is_string($relName) || !is_array($relDef)) {
                continue;
            }

            $relationships[] = $this->parseRelation($relName, $relDef);
        }

        return new EntityDefinition(
            className: $className,
            namespace: $namespace,
            tableName: $tableName,
            properties: $properties,
            relationships: $relationships,
            primaryKey: is_string($definition['primaryKey'] ?? null) ? $definition['primaryKey'] : 'id',
            hasTimestamps: ($definition['timestamps'] ?? false) === true,
            hasSoftDeletes: ($definition['softDeletes'] ?? false) === true,
            isAuditAware: ($definition['audit'] ?? false) === true,
        );
    }

    /**
     * @param array<mixed, mixed> $propDef
     */
    private function parseProperty(string $name, array $propDef): PropertyDefinition
    {
        $type = is_string($propDef['type'] ?? null) ? $propDef['type'] : 'string';
        $phpType = $this->schemaTypeToPhp($type);
        $nullable = ($propDef['nullable'] ?? false) === true;
        $columnName = is_string($propDef['column'] ?? null) ? $propDef['column'] : IdentifierNormalizer::toColumnName($name);
        $length = isset($propDef['length']) && is_int($propDef['length']) ? $propDef['length'] : null;

        return new PropertyDefinition(
            name: $name,
            phpType: $phpType,
            columnName: $columnName,
            columnType: $type,
            nullable: $nullable,
            hasDefault: isset($propDef['default']),
            defaultValue: $propDef['default'] ?? null,
            validationRules: [],
            isFilterable: ($propDef['filterable'] ?? true) === true,
            isSortable: ($propDef['sortable'] ?? true) === true,
            length: $length,
            isPrimaryKey: ($propDef['primaryKey'] ?? false) === true,
        );
    }

    /**
     * @param array<mixed, mixed> $relDef
     */
    private function parseRelation(string $name, array $relDef): RelationshipDefinition
    {
        $typeStr = is_string($relDef['type'] ?? null) ? $relDef['type'] : 'hasMany';
        $target = is_string($relDef['target'] ?? null) ? $relDef['target'] : '';
        $foreignKey = is_string($relDef['foreignKey'] ?? null) ? $relDef['foreignKey'] : null;

        $type = RelationType::tryFrom($typeStr) ?? RelationType::HasMany;

        return new RelationshipDefinition(
            type: $type,
            relatedEntity: $target,
            foreignKey: $foreignKey ?? $name . '_id',
            localKey: is_string($relDef['localKey'] ?? null) ? $relDef['localKey'] : 'id',
            pivotTable: is_string($relDef['pivot'] ?? null) ? $relDef['pivot'] : null,
        );
    }

    private function schemaTypeToPhp(string $type): string
    {
        return match ($type) {
            'string', 'text', 'varchar', 'char', 'uuid', 'enum' => 'string',
            'int', 'integer', 'bigint', 'smallint', 'tinyint' => 'int',
            'float', 'double', 'decimal', 'numeric' => 'float',
            'bool', 'boolean' => 'bool',
            'datetime', 'timestamp', 'date', 'time' => 'DateTimeImmutable',
            'json', 'jsonb' => 'array',
            'blob', 'binary' => 'string',
            default => 'mixed',
        };
    }
}
