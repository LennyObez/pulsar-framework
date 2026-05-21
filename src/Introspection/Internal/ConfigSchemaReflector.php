<?php

declare(strict_types=1);

namespace Pulsar\Introspection\Internal;

use Pulsar\Api\Internal;
use Pulsar\Introspection\Data\ConfigPropertySchema;
use Pulsar\Introspection\Data\ConfigSchemaData;
use Pulsar\Introspection\Data\ConfigSchemaEntry;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use Throwable;

use function is_object;

/**
 * Reflects on config DTO classes to extract property schemas.
 *
 * Extracts property names, declared types, and default values.
 * Sensitive-named properties have their defaults scrubbed.
 * Does NOT instantiate DTOs or read environment variables.
 */
#[Internal]
final readonly class ConfigSchemaReflector
{
    public function __construct(
        private SensitiveDataScrubber $scrubber,
    ) {}

    /**
     * Reflect on the given config DTO classes and extract schemas.
     *
     * @param list<class-string> $configClasses
     * @param list<string>       $warnings
     */
    public function reflect(array $configClasses, array &$warnings): ConfigSchemaData
    {
        $entries = [];

        foreach ($configClasses as $className) {
            try {
                $entry = $this->reflectClass($className);

                if ($entry !== null) {
                    $entries[] = $entry;
                }
            } catch (Throwable $e) {
                $warnings[] = 'Failed to reflect config class ' . $className . ': ' . $e->getMessage();
            }
        }

        return new ConfigSchemaData(schemas: $entries);
    }

    private function reflectClass(string $className): ?ConfigSchemaEntry
    {
        if (!class_exists($className)) {
            return null;
        }

        $reflection = new ReflectionClass($className);
        $properties = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $type = $property->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : 'mixed';

            if ($type instanceof ReflectionNamedType && $type->allowsNull()) {
                $typeName = '?' . $typeName;
            }

            /** @var mixed $default */
            $default = $this->extractDefault($property);

            // Scrub sensitive defaults
            $scrubbed = $this->scrubber->scrub([$property->getName() => $default]);
            /** @var mixed $scrubbedDefault */
            $scrubbedDefault = $scrubbed[$property->getName()];

            $properties[] = new ConfigPropertySchema(
                name: $property->getName(),
                type: $typeName,
                default: $scrubbedDefault,
            );
        }

        return new ConfigSchemaEntry(
            className: $className,
            properties: $properties,
        );
    }

    private function extractDefault(ReflectionProperty $property): mixed
    {
        if ($property->hasDefaultValue()) {
            /** @var mixed $default */
            $default = $property->getDefaultValue();

            // If default is an object (e.g., nested config DTO via `new`), represent as class name
            if (is_object($default)) {
                return $default::class;
            }

            return $default;
        }

        if ($property->isPromoted()) {
            // Promoted properties: check constructor parameters
            $constructor = $property->getDeclaringClass()->getConstructor();

            if ($constructor !== null) {
                foreach ($constructor->getParameters() as $param) {
                    if ($param->getName() === $property->getName() && $param->isDefaultValueAvailable()) {
                        /** @var mixed $default */
                        $default = $param->getDefaultValue();

                        if (is_object($default)) {
                            return $default::class;
                        }

                        return $default;
                    }
                }
            }
        }

        return null;
    }
}
