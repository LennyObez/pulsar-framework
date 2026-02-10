<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Hydration;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\Row;
use Pulsar\Extension\Orm\Contracts\ColumnEncryptorInterface;
use Pulsar\Extension\Orm\Contracts\EntityHydratorInterface;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;
use Pulsar\Extension\Orm\Internal\Support\TypeCaster;
use ReflectionClass;

use function array_map;

/**
 * Hydrates entity objects from database rows using metadata.
 */
#[Internal]
final class EntityHydrator implements EntityHydratorInterface
{
    private readonly TypeCaster $typeCaster;

    public function __construct(
        private readonly MetadataRegistryInterface $metadataRegistry,
        private readonly ?ColumnEncryptorInterface $encryptor = null,
    ) {
        $this->typeCaster = new TypeCaster();
    }

    #[Override]
    public function hydrate(string $entityClass, Row $row): object
    {
        $metadata = $this->metadataRegistry->get($entityClass);
        $reflection = new ReflectionClass($entityClass);
        $entity = $reflection->newInstanceWithoutConstructor();

        foreach ($metadata->columns as $col) {
            if (!$row->has($col->columnName)) {
                continue;
            }

            $value = $row->get($col->columnName);

            // Decrypt if encrypted
            if ($col->encrypted && $value !== null && $this->encryptor !== null) {
                /** @var string $binaryValue */
                $binaryValue = $row->getBinary($col->columnName);
                $value = $this->encryptor->decrypt($binaryValue);
            }

            // Apply custom caster
            if ($col->casterClass !== null && $value !== null) {
                /** @var callable $fromDb */
                $fromDb = [$col->casterClass, 'fromDatabase'];
                $value = $fromDb($value);
            } else {
                $value = $this->typeCaster->fromDatabase($value, $col->type);
            }

            $prop = $reflection->getProperty($col->propertyName);
            $prop->setValue($entity, $value);
        }

        return $entity;
    }

    #[Override]
    public function hydrateAll(string $entityClass, array $rows): array
    {
        return array_map(
            fn(Row $row): object => $this->hydrate($entityClass, $row),
            $rows,
        );
    }
}
