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
 *
 * Reflection metadata is cached per concrete LiveComponent class on
 * first use. Class structure cannot change at runtime, so the cache
 * is stable for the entire process lifetime — this turns the hot
 * hydrate/dehydrate paths from O(properties × attribute-parses) on
 * every render into a single hash lookup. The previous M-2 audit
 * finding flagged the per-call `new ReflectionClass()` as wasted
 * work in render loops.
 */
#[Internal]
final class ComponentHydrator
{
    /**
     * Per-class metadata cache: fully qualified class name => list of
     * tracked properties with their precomputed attribute settings.
     *
     * @var array<class-string<LiveComponent>, list<LivePropertyDescriptor>>
     */
    private static array $descriptorCache = [];

    /**
     * Extract the serializable state from a component.
     *
     * @return array<string, mixed>
     */
    public function dehydrate(LiveComponent $component): array
    {
        /** @var array<string, mixed> $state */
        $state = [];

        foreach ($this->describeComponent($component) as $descriptor) {
            /** @var mixed $value */
            $value = $descriptor->property->getValue($component);
            $state[$descriptor->name] = $this->serializeValue($value);
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
        foreach ($this->describeComponent($component) as $descriptor) {
            if (!$descriptor->writable) {
                continue;
            }

            if (!array_key_exists($descriptor->name, $state)) {
                continue;
            }

            /** @var mixed $value */
            $value = $this->deserializeValue($state[$descriptor->name], $descriptor->typeName);
            $descriptor->property->setValue($component, $value);
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

        foreach ($this->describeComponent($component) as $descriptor) {
            $names[] = $descriptor->name;
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

        foreach ($this->describeComponent($component) as $descriptor) {
            if ($descriptor->writable) {
                $names[] = $descriptor->name;
            }
        }

        return $names;
    }

    /**
     * Resolve (and cache) the descriptor list for a component class.
     *
     * Walks public properties exactly once per class and pre-computes
     * `LiveProp` settings, declared type, and the `ReflectionProperty`
     * accessor itself. Subsequent calls hit the static cache.
     *
     * @return list<LivePropertyDescriptor>
     */
    private function describeComponent(LiveComponent $component): array
    {
        $class = $component::class;

        if (isset(self::$descriptorCache[$class])) {
            return self::$descriptorCache[$class];
        }

        $reflection = new ReflectionClass($class);
        $descriptors = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $attributes = $property->getAttributes(LiveProp::class);

            if ($attributes === []) {
                continue;
            }

            /** @var LiveProp $liveProp */
            $liveProp = $attributes[0]->newInstance();
            $type = $property->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : 'mixed';

            $descriptors[] = new LivePropertyDescriptor(
                name: $property->getName(),
                property: $property,
                writable: $liveProp->writable,
                typeName: $typeName,
            );
        }

        self::$descriptorCache[$class] = $descriptors;

        return $descriptors;
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
