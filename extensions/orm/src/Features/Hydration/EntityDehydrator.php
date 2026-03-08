<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Hydration;

use Pulsar\Api\Internal;
use Pulsar\Database\Param;
use Pulsar\Extension\Orm\Contracts\ColumnEncryptorInterface;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;
use Pulsar\Extension\Orm\Internal\Support\TypeCaster;
use ReflectionClass;
use ReflectionProperty;

use function is_scalar;
use function is_string;

/**
 * Dehydrates entity objects into database-ready column => value arrays.
 *
 * Reflection metadata is cached per entity class in a static lookup.
 * Entity structure is immutable at runtime so the cache is sound; it
 * removes the per-call `new ReflectionClass()` + repeated `getProperty`
 * cost that dominated the dehydration path (ORM C-1 ext-audit).
 */
#[Internal]
final class EntityDehydrator
{
    /**
     * Per-class reflection cache.
     *
     * Shape: `[entityClass => ['reflection' => ReflectionClass, 'properties' => array<string, ReflectionProperty>]]`
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
     * Dehydrate an entity into a column => value map for INSERT.
     *
     * @return array<string, mixed>
     */
    public function dehydrateForInsert(object $entity): array
    {
        $metadata = $this->metadataRegistry->get($entity::class);
        $reflectionData = self::reflectionFor($entity::class);
        $values = [];

        foreach ($metadata->insertableColumns() as $col) {
            $value = $this->extractValue($reflectionData, $entity, $col);
            $values[$col->columnName] = $value;

            // Add blind index if encrypted
            if ($col->encrypted && $col->blindIndexColumn !== null && $this->encryptor !== null) {
                $rawProp = self::propertyFor($reflectionData, $col->propertyName);
                $rawValue = $rawProp->getValue($entity);
                if ($rawValue !== null) {
                    $strValue = is_string($rawValue) ? $rawValue : (is_scalar($rawValue) ? (string) $rawValue : '');
                    $hash = $this->encryptor->blindIndex($strValue, $col->blindIndexHashLength ?? 32);
                    $values[$col->blindIndexColumn] = Param::binary($hash);
                }
            }
        }

        return $values;
    }

    /**
     * Dehydrate an entity into a column => value map for UPDATE.
     *
     * @return array<string, mixed>
     */
    public function dehydrateForUpdate(object $entity): array
    {
        $metadata = $this->metadataRegistry->get($entity::class);
        $reflectionData = self::reflectionFor($entity::class);
        $values = [];

        foreach ($metadata->updatableColumns() as $col) {
            $value = $this->extractValue($reflectionData, $entity, $col);
            $values[$col->columnName] = $value;

            // Update blind index if encrypted
            if ($col->encrypted && $col->blindIndexColumn !== null && $this->encryptor !== null) {
                $rawProp = self::propertyFor($reflectionData, $col->propertyName);
                $rawValue = $rawProp->getValue($entity);
                if ($rawValue !== null) {
                    $strValue = is_string($rawValue) ? $rawValue : (is_scalar($rawValue) ? (string) $rawValue : '');
                    $hash = $this->encryptor->blindIndex($strValue, $col->blindIndexHashLength ?? 32);
                    $values[$col->blindIndexColumn] = Param::binary($hash);
                }
            }
        }

        return $values;
    }

    /**
     * Extract the primary key value from an entity.
     */
    public function extractId(object $entity): string|int
    {
        $metadata = $this->metadataRegistry->get($entity::class);
        $reflectionData = self::reflectionFor($entity::class);
        $prop = self::propertyFor($reflectionData, $metadata->primaryKey->propertyName);

        /** @var string|int */
        return $prop->getValue($entity);
    }

    /**
     * Extract the version value from an entity (for optimistic locking).
     */
    public function extractVersion(object $entity): ?int
    {
        $metadata = $this->metadataRegistry->get($entity::class);
        if ($metadata->versionProperty === null) {
            return null;
        }

        $reflectionData = self::reflectionFor($entity::class);
        $prop = self::propertyFor($reflectionData, $metadata->versionProperty);

        /** @var int|null */
        return $prop->getValue($entity);
    }

    /**
     * @param array{reflection: ReflectionClass<object>, properties: array<string, ReflectionProperty>} $reflectionData
     */
    private function extractValue(array $reflectionData, object $entity, ColumnMetadata $col): mixed
    {
        $prop = self::propertyFor($reflectionData, $col->propertyName);
        $value = $prop->getValue($entity);

        // Apply custom caster
        if ($col->casterClass !== null && $value !== null) {
            /** @var callable $toDb */
            $toDb = [$col->casterClass, 'toDatabase'];
            $value = $toDb($value);
        } else {
            $value = $this->typeCaster->toDatabase($value, $col->type);
        }

        // Encrypt if needed
        if ($col->encrypted && $value !== null && $this->encryptor !== null) {
            $strValue = is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
            $encrypted = $this->encryptor->encrypt($strValue);

            return Param::binary($encrypted);
        }

        return $value;
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

    /**
     * Resolve (and lazily cache) a property on the reflection descriptor.
     *
     * @param array{reflection: ReflectionClass<object>, properties: array<string, ReflectionProperty>} $reflectionData
     */
    private static function propertyFor(array $reflectionData, string $propertyName): ReflectionProperty
    {
        $reflection = $reflectionData['reflection'];
        $entityClass = $reflection->getName();

        if (isset(self::$reflectionCache[$entityClass]['properties'][$propertyName])) {
            return self::$reflectionCache[$entityClass]['properties'][$propertyName];
        }

        $prop = $reflection->getProperty($propertyName);
        self::$reflectionCache[$entityClass]['properties'][$propertyName] = $prop;

        return $prop;
    }
}
