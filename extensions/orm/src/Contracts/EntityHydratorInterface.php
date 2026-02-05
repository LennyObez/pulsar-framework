<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Contracts;

use Pulsar\Api\Api;
use Pulsar\Database\Row;

/**
 * Hydrates entity objects from database rows.
 */
#[Api(since: '1.0.0')]
interface EntityHydratorInterface
{
    /**
     * Hydrate a single entity from a database row.
     *
     * @template T of object
     * @param class-string<T> $entityClass
     * @return T
     */
    public function hydrate(string $entityClass, Row $row): object;

    /**
     * Hydrate a list of entities from database rows.
     *
     * @template T of object
     * @param class-string<T> $entityClass
     * @param list<Row> $rows
     * @return list<T>
     */
    public function hydrateAll(string $entityClass, array $rows): array;
}
