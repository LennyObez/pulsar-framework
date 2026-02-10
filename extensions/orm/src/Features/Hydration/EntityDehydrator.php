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

/**
 * Dehydrates entity objects into database-ready column => value arrays.
 */
#[Internal]
final readonly class EntityDehydrator
{
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
        $reflection = new ReflectionClass($entity);
        $values = [];

        foreach ($metadata->insertableColumns() as $col) {
            $value = $this->extractValue($reflection, $entity, $col);
            $values[$col->columnName] = $value;

            // Add blind index if encrypted
            if ($col->encrypted && $col->blindIndexColumn !== null && $this->encryptor !== null) {
                $rawValue = $reflection->getProperty($col->propertyName)->getValue($entity);
                if ($rawValue !== null) {
                    $hash = $this->encryptor->blindIndex((string) $rawValue, $col->blindIndexHashLength ?? 32);
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
        $reflection = new ReflectionClass($entity);
        $values = [];

        foreach ($metadata->updatableColumns() as $col) {
            $value = $this->extractValue($reflection, $entity, $col);
            $values[$col->columnName] = $value;

            // Update blind index if encrypted
            if ($col->encrypted && $col->blindIndexColumn !== null && $this->encryptor !== null) {
                $rawValue = $reflection->getProperty($col->propertyName)->getValue($entity);
                if ($rawValue !== null) {
                    $hash = $this->encryptor->blindIndex((string) $rawValue, $col->blindIndexHashLength ?? 32);
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
        $reflection = new ReflectionClass($entity);
        $prop = $reflection->getProperty($metadata->primaryKey->propertyName);

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

        $reflection = new ReflectionClass($entity);
        $prop = $reflection->getProperty($metadata->versionProperty);

        /** @var int|null */
        return $prop->getValue($entity);
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function extractValue(ReflectionClass $reflection, object $entity, ColumnMetadata $col): mixed
    {
        $prop = $reflection->getProperty($col->propertyName);
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
            $encrypted = $this->encryptor->encrypt((string) $value);

            return Param::binary($encrypted);
        }

        return $value;
    }
}
