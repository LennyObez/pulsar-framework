<?php

declare(strict_types=1);

namespace Pulsar\Support\Mapper;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use NoDiscard;
use Pulsar\Api\Api;
use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;
use UnitEnum;
use ValueError;

use function array_key_exists;
use function class_exists;
use function enum_exists;
use function is_a;
use function is_array;
use function is_int;
use function is_string;
use function lcfirst;
use function method_exists;
use function preg_replace_callback;
use function str_contains;
use function strtolower;

/**
 * Automatic array-to-object mapper using PHP 8.5 reflection.
 *
 * Maps associative arrays to typed DTOs by matching array keys to
 * constructor parameter names. Handles nested DTOs, enums,
 * DateTimeImmutable, and configurable naming strategies.
 *
 * Integrates with Pulsar's fromArray() convention: if the target class
 * has a static fromArray() method, it is used instead of reflection.
 */
#[Api(since: '1.0.0')]
final class ObjectMapper
{
    /** @var array<class-string, list<ReflectionParameter>> */
    private static array $paramCache = [];

    private readonly NamingStrategy $namingStrategy;

    public function __construct(NamingStrategy $namingStrategy = NamingStrategy::Identity)
    {
        $this->namingStrategy = $namingStrategy;
    }

    /**
     * Map an associative array to a typed object.
     *
     * @template T of object
     * @param array<mixed, mixed> $data Source data
     * @param class-string<T> $targetClass Target class name
     * @return T Hydrated object
     *
     * @throws MappingException If mapping fails
     */
    #[NoDiscard]
    public function map(array $data, string $targetClass): object
    {
        // Check for fromArray() factory first (Pulsar convention)
        if (method_exists($targetClass, 'fromArray')) {
            try {
                /** @var T */
                return $targetClass::fromArray($data);
            } catch (Throwable $e) {
                throw MappingException::factoryFailed($targetClass, $e);
            }
        }

        return $this->mapViaConstructor($data, $targetClass);
    }

    /**
     * Map a list of arrays to a list of objects.
     *
     * @template T of object
     * @param list<array<mixed, mixed>> $items
     * @param class-string<T> $targetClass
     * @return list<T>
     *
     * @throws MappingException If mapping fails
     */
    #[NoDiscard]
    public function mapList(array $items, string $targetClass): array
    {
        $results = [];

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                throw MappingException::invalidListItem($targetClass, $index);
            }

            $results[] = $this->map($item, $targetClass);
        }

        return $results;
    }

    /**
     * Clear the reflection parameter cache.
     */
    public static function clearCache(): void
    {
        self::$paramCache = [];
    }

    /**
     * @template T of object
     * @param array<mixed, mixed> $data
     * @param class-string<T> $targetClass
     * @return T
     */
    private function mapViaConstructor(array $data, string $targetClass): object
    {
        if (!class_exists($targetClass)) {
            throw MappingException::classNotFound($targetClass);
        }

        $reflection = new ReflectionClass($targetClass);

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            if ($data !== []) {
                throw MappingException::noConstructor($targetClass);
            }

            /** @var T */
            return $reflection->newInstance();
        }

        $params = self::$paramCache[$targetClass] ?? null;

        if ($params === null) {
            $params = $constructor->getParameters();
            self::$paramCache[$targetClass] = $params;
        }

        $args = [];

        foreach ($params as $param) {
            $key = $this->resolveKey($param->getName(), $data);

            if ($key !== null && array_key_exists($key, $data)) {
                $args[] = $this->coerce($data[$key], $param, $targetClass);
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } elseif ($param->allowsNull()) {
                $args[] = null;
            } else {
                throw MappingException::missingRequired($targetClass, $param->getName());
            }
        }

        try {
            /** @var T */
            return $reflection->newInstanceArgs($args);
        } catch (Throwable $e) {
            throw MappingException::constructionFailed($targetClass, $e);
        }
    }

    /**
     * Find the matching key in data for a parameter name, considering naming strategy.
     *
     * @param array<mixed, mixed> $data
     */
    private function resolveKey(string $paramName, array $data): ?string
    {
        // Direct match first
        if (array_key_exists($paramName, $data)) {
            return $paramName;
        }

        // Try alternate naming
        $alternate = match ($this->namingStrategy) {
            NamingStrategy::Identity => null,
            NamingStrategy::CamelToSnake => self::camelToSnake($paramName),
            NamingStrategy::SnakeToCamel => self::snakeToCamel($paramName),
        };

        if ($alternate !== null && array_key_exists($alternate, $data)) {
            return $alternate;
        }

        return null;
    }

    /**
     * Coerce a value to match the expected parameter type.
     */
    private function coerce(mixed $value, ReflectionParameter $param, string $contextClass): mixed
    {
        $type = $param->getType();

        if (!$type instanceof ReflectionNamedType) {
            return $value;
        }

        $typeName = $type->getName();

        // Null passthrough
        if ($value === null) {
            if ($type->allowsNull()) {
                return null;
            }

            throw MappingException::nullNotAllowed($contextClass, $param->getName());
        }

        // Scalar types: no coercion needed
        if ($typeName === 'string' || $typeName === 'int' || $typeName === 'float' || $typeName === 'bool' || $typeName === 'array' || $typeName === 'mixed') {
            return $value;
        }

        // DateTimeImmutable
        if ($typeName === DateTimeImmutable::class || $typeName === DateTimeInterface::class) {
            if ($value instanceof DateTimeImmutable) {
                return $value;
            }

            if (is_string($value)) {
                $dt = DateTimeImmutable::createFromFormat(DateTimeInterface::RFC3339, $value);

                if ($dt === false) {
                    $dt = new DateTimeImmutable($value);
                }

                return $dt;
            }

            if (is_int($value)) {
                return new DateTimeImmutable()->setTimestamp($value);
            }

            throw MappingException::typeMismatch($contextClass, $param->getName(), $typeName, $value);
        }

        // Backed enums
        if (enum_exists($typeName) && is_a($typeName, BackedEnum::class, true)) {
            if ($value instanceof $typeName) {
                return $value;
            }

            if (!is_string($value) && !is_int($value)) {
                throw MappingException::typeMismatch($contextClass, $param->getName(), $typeName, $value);
            }

            try {
                /** @var class-string<BackedEnum> $typeName */
                return $typeName::from($value);
            } catch (ValueError $e) {
                throw MappingException::enumFailed($contextClass, $param->getName(), $typeName, $value, $e);
            }
        }

        // Unit enums (non-backed)
        if (enum_exists($typeName)) {
            if ($value instanceof $typeName) {
                return $value;
            }

            if (is_string($value)) {
                /** @var class-string<UnitEnum> $typeName */
                $refEnum = new ReflectionEnum($typeName);

                if ($refEnum->hasCase($value)) {
                    return $refEnum->getCase($value)->getValue();
                }
            }

            throw MappingException::enumFailed($contextClass, $param->getName(), $typeName, $value);
        }

        // Nested DTO
        if (is_array($value) && class_exists($typeName)) {
            return $this->map($value, $typeName);
        }

        // Already correct type
        if ($value instanceof $typeName) {
            return $value;
        }

        return $value;
    }

    private static function camelToSnake(string $name): string
    {
        return strtolower((string) preg_replace_callback(
            '/[A-Z]/',
            static fn(array $m): string => '_' . strtolower($m[0]),
            $name,
        ));
    }

    private static function snakeToCamel(string $name): string
    {
        if (!str_contains($name, '_')) {
            return $name;
        }

        return lcfirst((string) preg_replace_callback(
            '/_([a-z])/',
            static fn(array $m): string => strtoupper($m[1]),
            $name,
        ));
    }
}
