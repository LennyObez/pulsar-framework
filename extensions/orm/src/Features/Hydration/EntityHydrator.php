<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Hydration;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\Row;
use Pulsar\Extension\Orm\Contracts\ColumnEncryptorInterface;
use Pulsar\Extension\Orm\Contracts\EntityHydratorInterface;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Internal\Support\TypeCaster;
use ReflectionClass;
use ReflectionProperty;

/**
 * Hydrates entity objects from database rows using metadata.
 *
 * Reflection metadata (ReflectionClass + per-column ReflectionProperty)
 * is cached per entity class in a static lookup. For a query returning
 * 1000 rows, this turns 1000 × (new ReflectionClass + N × getProperty)
 * into one lookup per row, eliminating the dominant hydration overhead
 * flagged by the ORM C-1 ext-audit finding. The cache is sound because
 * entity class structure cannot change at runtime.
 */
#[Internal]
final class EntityHydrator implements EntityHydratorInterface
{
    /**
     * Per-class reflection cache.
     *
     * Shape: `[entityClass => ['reflection' => ReflectionClass, 'properties' => array<string, ReflectionProperty>]]`
     * where `properties` is keyed by property name.
     *
     * @var array<class-string, array{reflection: ReflectionClass<object>, properties: array<string, ReflectionProperty>}>
     */
    private static array $reflectionCache = [];

    private readonly TypeCaster $typeCaster;

    public function __construct(
        private readonly MetadataRegistryInterface $metadataRegistry,
        private readonly ?ColumnEncryptorInterface $encryptor = null,
    ) {
        $this->typeCaster = new TypeCaster();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $entityClass
     *
     * @return T
     */
    #[Override]
    public function hydrate(string $entityClass, Row $row): object
    {
        $metadata = $this->metadataRegistry->get($entityClass);
        $reflectionData = self::reflectionFor($entityClass);

        /** @var T $entity */
        $entity = $reflectionData['reflection']->newInstanceWithoutConstructor();
        $properties = $reflectionData['properties'];

        foreach ($metadata->columns as $col) {
            if (!$row->has($col->columnName)) {
                continue;
            }

            /** @var mixed $value */
            $value = $row->get($col->columnName);

            // Decrypt if encrypted
            if ($col->encrypted && $value !== null && $this->encryptor !== null) {
                $binaryValue = $row->getBinary($col->columnName);
                $value = $this->encryptor->decrypt($binaryValue);
            }

            // Apply custom caster
            if ($col->casterClass !== null && $value !== null) {
                /** @var callable $fromDb */
                $fromDb = [$col->casterClass, 'fromDatabase'];
                /** @var mixed $value */
                $value = $fromDb($value);
            } else {
                $value = $this->typeCaster->fromDatabase($value, $col->type);
            }

            $propertyName = $col->propertyName;
            $prop = $properties[$propertyName]
                ??= $reflectionData['reflection']->getProperty($propertyName);
            $prop->setValue($entity, $value);

            // Keep the lazily-resolved ReflectionProperty in the cache
            // so subsequent rows for the same entity skip the lookup.
            self::$reflectionCache[$entityClass]['properties'][$propertyName] = $prop;
        }

        return $entity;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $entityClass
     * @param list<Row>       $rows
     *
     * @return list<T>
     */
    #[Override]
    public function hydrateAll(string $entityClass, array $rows): array
    {
        $hydrated = [];

        foreach ($rows as $row) {
            $hydrated[] = $this->hydrate($entityClass, $row);
        }

        return $hydrated;
    }

    /**
     * Resolve (and cache) the reflection descriptor for an entity class.
     *
     * @param class-string $entityClass
     *
     * @return array{reflection: ReflectionClass<object>, properties: array<string, ReflectionProperty>}
     */
    private static function reflectionFor(string $entityClass): array
    {
        if (isset(self::$reflectionCache[$entityClass])) {
            return self::$reflectionCache[$entityClass];
        }

        /** @var ReflectionClass<object> $reflection */
        $reflection = new ReflectionClass($entityClass);

        return self::$reflectionCache[$entityClass] = [
            'reflection' => $reflection,
            'properties' => [],
        ];
    }
}
