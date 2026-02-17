<?php

declare(strict_types=1);

namespace Pulsar\Live\Internal;

use Pulsar\Api\Internal;
use Pulsar\Live\LiveComponent;
use Pulsar\Live\LiveProp;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

use function array_key_exists;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Handles hydration (deserialize state → component) and
 * dehydration (component → serialized state) for live components.
 */
#[Internal]
final readonly class ComponentHydrator
{
    /**
     * Extract the serializable state from a component.
     *
     * @return array<string, mixed>
     */
    public function dehydrate(LiveComponent $component): array
    {
        $state = [];
        $reflection = new ReflectionClass($component);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $attributes = $property->getAttributes(LiveProp::class);

            if ($attributes === []) {
                continue;
            }

            $name = $property->getName();
            $value = $property->getValue($component);

            $state[$name] = $this->serializeValue($value);
        }

        return $state;
    }

    /**
     * Apply serialized state to a component instance.
     *
     * @param array<string, mixed> $state
     */
    public function hydrate(LiveComponent $component, array $state): void
    {
        $reflection = new ReflectionClass($component);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $attributes = $property->getAttributes(LiveProp::class);

            if ($attributes === []) {
                continue;
            }

            $name = $property->getName();

            if (!array_key_exists($name, $state)) {
                continue;
            }

            /** @var LiveProp $liveProp */
            $liveProp = $attributes[0]->newInstance();

            // Only hydrate writable properties from client state
            if (!$liveProp->writable) {
                continue;
            }

            $type = $property->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : 'mixed';
            $value = $this->deserializeValue($state[$name], $typeName);
            $property->setValue($component, $value);
        }
    }

    /**
     * Get the names of all tracked properties.
     *
     * @return list<string>
     */
    public function getTrackedPropertyNames(LiveComponent $component): array
    {
        $names = [];
        $reflection = new ReflectionClass($component);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getAttributes(LiveProp::class) !== []) {
                $names[] = $property->getName();
            }
        }

        return $names;
    }

    /**
     * Get the names of all writable properties.
     *
     * @return list<string>
     */
    public function getWritablePropertyNames(LiveComponent $component): array
    {
        $names = [];
        $reflection = new ReflectionClass($component);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $attributes = $property->getAttributes(LiveProp::class);

            if ($attributes === []) {
                continue;
            }

            /** @var LiveProp $liveProp */
            $liveProp = $attributes[0]->newInstance();

            if ($liveProp->writable) {
                $names[] = $property->getName();
            }
        }

        return $names;
    }

    private function serializeValue(mixed $value): mixed
    {
        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        if (is_array($value)) {
            return $value;
        }

        // Fallback: JSON-encode non-primitive values
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    private function deserializeValue(mixed $value, string $typeName): mixed
    {
        return match ($typeName) {
            'int' => (is_numeric($value) ? (int) $value : 0),
            'float' => (is_numeric($value) ? (float) $value : 0.0),
            'bool' => (bool) $value,
            'string' => (is_string($value) ? $value : ''),
            'array' => is_array($value) ? $value : [],
            default => $value,
        };
    }
}
