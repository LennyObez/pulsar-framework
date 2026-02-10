<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Relation;

use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Orm\Contracts\EntityHydratorInterface;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Features\Query\SelectBuilder;

use function array_chunk;
use function array_merge;

/**
 * Loads related entities in batches to avoid excessive IN clause sizes.
 */
#[Internal]
final readonly class BatchLoader
{
    private const int DEFAULT_BATCH_SIZE = 500;

    public function __construct(
        private ConnectionInterface $connection,
        private MetadataRegistryInterface $metadataRegistry,
        private EntityHydratorInterface $hydrator,
        private int $batchSize = self::DEFAULT_BATCH_SIZE,
    ) {}

    /**
     * Load entities by a set of IDs, batched for large sets.
     *
     * @param class-string $entityClass
     * @param list<mixed> $ids
     * @return list<object>
     */
    public function loadByIds(string $entityClass, string $column, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $metadata = $this->metadataRegistry->get($entityClass);
        $chunks = array_chunk($ids, $this->batchSize);
        $results = [];

        foreach ($chunks as $chunk) {
            $builder = new SelectBuilder($this->connection);
            $builder->forEntity($entityClass, $metadata, $this->hydrator);
            $builder->whereIn($column, $chunk);

            $results[] = $builder->getEntities();
        }

        return array_merge(...$results);
    }
}
