<?php

declare(strict_types=1);

namespace Pulsar\Api\OpenApi;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Pulsar\Api\Api;
use Pulsar\Api\OpenApi\Attribute\ApiField;
use ReflectionClass;
use ReflectionEnum;
use ReflectionEnumBackedCase;
use ReflectionEnumUnitCase;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use stdClass;
use UnitEnum;

use function array_key_exists;
use function array_map;
use function class_exists;
use function count;
use function enum_exists;
use function explode;
use function in_array;

/**
 * Infers JSON Schema definitions from DTO and resource class declarations.
 *
 * Designed for build-time use only. Reads class metadata (constructor parameters,
 * public properties, property hooks) to produce OpenAPI-compatible JSON Schema
 * objects, including Pulsar compliance vendor extensions from `#[ApiField]`.
 * @api
 */
#[Api(since: '1.0.0')]
final class SchemaInferrer
{
    /** @var array<class-string, array<string, mixed>> */
    private array $schemaCache = [];

    /**
     * Infer a JSON Schema from a class declaration.
     *
     * @param class-string $className Fully qualified class name of the DTO or resource
     *
     * @return array<string, mixed> OpenAPI-compatible JSON Schema object
     */
    public function infer(string $className): array
    {
        if (array_key_exists($className, $this->schemaCache)) {
            return $this->schemaCache[$className];
        }

        $reflection = new ReflectionClass($className);
        $properties = $this->collectProperties($reflection);

        $schema = [
            'type' => 'object',
            'properties' => [],
        ];

        $required = [];

        foreach ($properties as $property) {
            $propertySchema = $this->inferPropertySchema($property);
            $schema['properties'][$property->getName()] = $propertySchema;

            if (!$property->getType()?->allowsNull() && !$property->hasDefaultValue()) {
                $required[] = $property->getName();
            }
        }

        if ($required !== []) {
            $schema['required'] = $required;
        }

        $this->schemaCache[$className] = $schema;

        return $schema;
    }

    /**
     * Infer the short schema name from a fully-qualified class name.
     *
     * @param class-string $className
     */
    public function shortName(string $className): string
    {
        $parts = explode('\\', $className);

        return end($parts);
    }

    /**
     * Collect all public properties from the class (including promoted constructor parameters).
     *
     * @param ReflectionClass<object> $reflection
     *
     * @return list<ReflectionProperty>
     */
    private function collectProperties(ReflectionClass $reflection): array
    {
        $properties = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $properties[] = $property;
        }

        return $properties;
    }

    /**
     * Infer the JSON Schema for a single property, including compliance vendor extensions.
     *
     * @return array<string, mixed>
     */
    private function inferPropertySchema(ReflectionProperty $property): array
    {
        $type = $property->getType();
        $schema = $this->typeToSchema($type);

        // Apply ApiField compliance metadata
        $apiFieldAttrs = $property->getAttributes(ApiField::class);
        if ($apiFieldAttrs !== []) {
            /** @var ApiField $apiField */
            $apiField = $apiFieldAttrs[0]->newInstance();
            $schema['x-pulsar-classification'] = $apiField->classification->value;

            if ($apiField->accessLevel !== null) {
                $schema['x-pulsar-access-level'] = $apiField->accessLevel;
            }

            if ($apiField->redacted) {
                $schema['x-pulsar-redacted'] = true;
            }

            if ($apiField->description !== null) {
                $schema['description'] = $apiField->description;
            }

            if ($apiField->example !== null) {
                $schema['example'] = $apiField->example;
            }
        }

        return $schema;
    }

    /**
     * Convert a PHP reflection type to a JSON Schema type definition.
     *
     * @return array<string, mixed>
     */
    private function typeToSchema(ReflectionType|null $type): array
    {
        if ($type === null) {
            return [];
        }

        if ($type instanceof ReflectionUnionType) {
            return $this->unionTypeToSchema($type);
        }

        if (!$type instanceof ReflectionNamedType) {
            return [];
        }

        return $this->namedTypeToSchema($type);
    }

    /**
     * @return array<string, mixed>
     */
    private function namedTypeToSchema(ReflectionNamedType $type): array
    {
        $typeName = $type->getName();

        $schema = match ($typeName) {
            'string' => ['type' => 'string'],
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number', 'format' => 'double'],
            'bool' => ['type' => 'boolean'],
            'array' => ['type' => 'array', 'items' => new stdClass()],
            'mixed' => [],
            'null' => ['type' => 'string', 'nullable' => true],
            default => $this->resolveComplexType($typeName),
        };

        if ($type->allowsNull() && $typeName !== 'null' && $typeName !== 'mixed') {
            $schema['nullable'] = true;
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function unionTypeToSchema(ReflectionUnionType $type): array
    {
        $schemas = [];
        $nullable = false;

        foreach ($type->getTypes() as $innerType) {
            if ($innerType instanceof ReflectionNamedType && $innerType->getName() === 'null') {
                $nullable = true;
                continue;
            }
            $schemas[] = $this->typeToSchema($innerType);
        }

        if (count($schemas) === 1) {
            $result = $schemas[0];
            if ($nullable) {
                $result['nullable'] = true;
            }
            return $result;
        }

        $result = ['oneOf' => $schemas];
        if ($nullable) {
            $result['nullable'] = true;
        }

        return $result;
    }

    /**
     * Resolve a complex (class/enum) type name to a JSON Schema reference or inline schema.
     *
     * @return array<string, mixed>
     */
    private function resolveComplexType(string $typeName): array
    {
        if (enum_exists($typeName)) {
            return $this->enumToSchema($typeName);
        }

        if (class_exists($typeName)) {
            if (in_array($typeName, [DateTimeInterface::class, DateTimeImmutable::class, DateTime::class], true)) {
                return ['type' => 'string', 'format' => 'date-time'];
            }

            $shortName = $this->shortName($typeName);
            return ['$ref' => '#/components/schemas/' . $shortName];
        }

        return ['type' => 'string'];
    }

    /**
     * Convert a backed enum to a JSON Schema with enum values.
     *
     * @param class-string $enumClass
     *
     * @return array<string, mixed>
     */
    private function enumToSchema(string $enumClass): array
    {
        $reflection = new ReflectionClass($enumClass);

        if (!$reflection->isEnum()) {
            return ['type' => 'string'];
        }

        /** @var class-string<UnitEnum> $enumClass */
        $enumReflection = new ReflectionEnum($enumClass);

        if (!$enumReflection->isBacked()) {
            $cases = array_map(
                static fn(ReflectionEnumUnitCase $case): string => $case->getName(),
                $enumReflection->getCases(),
            );
            return ['type' => 'string', 'enum' => $cases];
        }

        $backingType = $enumReflection->getBackingType();
        $jsonType = ($backingType instanceof ReflectionNamedType && $backingType->getName() === 'int')
            ? 'integer'
            : 'string';

        /** @var list<ReflectionEnumBackedCase> $backedCases */
        $backedCases = $enumReflection->getCases();
        $values = array_map(
            static fn(ReflectionEnumBackedCase $case): string|int => $case->getBackingValue(),
            $backedCases,
        );

        return ['type' => $jsonType, 'enum' => $values];
    }
}
