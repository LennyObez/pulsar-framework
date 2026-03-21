<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Binding;

use Pulsar\Api\Api;
use ReflectionProperty;

use function explode;
use function is_object;
use function property_exists;

/**
 * Reads and writes DTO properties for form data binding.
 *
 * Supports nested property paths (e.g., "address.city") and
 * handles readonly properties via reflection.
 * @api
 */
#[Api(since: '1.0.0')]
final class PropertyAccessor
{
    /**
     * Read a property value from an object, supporting dot-notation paths.
     */
    public function read(object $object, string $path): mixed
    {
        $segments = explode('.', $path);

        $current = $object;

        foreach ($segments as $segment) {
            if (!is_object($current) || !property_exists($current, $segment)) {
                return null;
            }

            $current = $this->readProperty($current, $segment);
        }

        return $current;
    }

    /**
     * Write a property value on an object, supporting dot-notation paths.
     */
    public function write(object $object, string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $lastSegment = array_pop($segments);

        $current = $object;

        foreach ($segments as $segment) {
            if (!is_object($current) || !property_exists($current, $segment)) {
                return;
            }

            $current = $this->readProperty($current, $segment);
        }

        if (is_object($current) && property_exists($current, $lastSegment)) {
            $this->writeProperty($current, $lastSegment, $value);
        }
    }

    private function readProperty(object $object, string $property): mixed
    {
        $ref = new ReflectionProperty($object, $property);

        return $ref->getValue($object);
    }

    private function writeProperty(object $object, string $property, mixed $value): void
    {
        $ref = new ReflectionProperty($object, $property);
        $ref->setValue($object, $value);
    }
}
