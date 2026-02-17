<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Binding;

use Pulsar\Api\Api;
use Pulsar\Extension\Form\Contract\FormInterface;
use ReflectionClass;
use ReflectionNamedType;

use function is_numeric;
use function is_scalar;

/**
 * Binds form data to DTOs and vice versa.
 *
 * Hydrates a DTO from form submission data with automatic type coercion,
 * and populates form fields from an existing DTO for editing scenarios.
 */
#[Api(since: '1.0.0')]
final class FormDataBinder
{
    public function __construct(
        private readonly PropertyAccessor $accessor,
    ) {}

    /**
     * Hydrate a DTO from form submission data.
     *
     * @template T of object
     *
     * @param class-string<T> $dtoClass
     *
     * @return T
     */
    public function hydrate(FormInterface $form, string $dtoClass): object
    {
        $reflection = new ReflectionClass($dtoClass);
        $dto = $reflection->newInstanceWithoutConstructor();

        $data = $form->getData();

        foreach ($data as $field => $value) {
            if (!$reflection->hasProperty($field)) {
                continue;
            }

            $property = $reflection->getProperty($field);
            $type = $property->getType();

            $coerced = $type instanceof ReflectionNamedType
                ? $this->coerce($value, $type)
                : $value;

            $this->accessor->write($dto, $field, $coerced);
        }

        return $dto;
    }

    /**
     * Populate form fields from a DTO (reverse binding).
     */
    public function populate(FormInterface $form, object $dto): void
    {
        foreach ($form->getFields() as $name => $field) {
            $value = $this->accessor->read($dto, $name);

            if ($value !== null) {
                $field->setValue($value);
            }
        }
    }

    /**
     * Coerce a value to match the target property type.
     */
    private function coerce(mixed $value, ReflectionNamedType $type): mixed
    {
        if ($value === null) {
            return $type->allowsNull() ? null : $this->defaultForType($type->getName());
        }

        return match ($type->getName()) {
            'int' => is_numeric($value) ? (int) $value : 0,
            'float' => is_numeric($value) ? (float) $value : 0.0,
            'bool' => (bool) $value,
            'string' => is_scalar($value) ? (string) $value : '',
            default => $value,
        };
    }

    private function defaultForType(string $typeName): mixed
    {
        return match ($typeName) {
            'int' => 0,
            'float' => 0.0,
            'bool' => false,
            'string' => '',
            'array' => [],
            default => null,
        };
    }
}
