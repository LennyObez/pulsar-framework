<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Contracts;

use Pulsar\Api\Api;
use Pulsar\Audit\MutationContext;
use Pulsar\Extension\Orm\Domain\EntityId;
use Pulsar\Extension\Orm\Domain\FetchPlan;
use Pulsar\Extension\Orm\Exception\EntityNotFoundException;
use Pulsar\Extension\Orm\Exception\OptimisticLockException;
use Pulsar\Extension\Orm\Exception\OrmException;

/**
 * Generic repository for entity persistence operations.
 *
 * All write operations require a MutationContext for audit trail.
 *
 * @template T of object
 */
#[Api(since: '1.0.0')]
interface RepositoryInterface
{
    /**
     * Find an entity by primary key.
     *
     * @return T|null
     */
    public function find(string|int $id, ?FetchPlan $fetchPlan = null): ?object;

    /**
     * Find an entity by primary key or throw.
     *
     * @return T
     * @throws EntityNotFoundException
     */
    public function findOrFail(string|int $id, ?FetchPlan $fetchPlan = null): object;

    /**
     * Find all entities matching optional criteria.
     *
     * @param array<string, mixed> $criteria Column => value filter
     * @return list<T>
     */
    public function findBy(array $criteria = [], ?FetchPlan $fetchPlan = null): array;

    /**
     * Find a single entity by criteria or return null.
     *
     * @param array<string, mixed> $criteria
     * @return T|null
     */
    public function findOneBy(array $criteria, ?FetchPlan $fetchPlan = null): ?object;

    /**
     * Get a query builder for this entity.
     */
    public function query(): RowQueryBuilderInterface;

    /**
     * Persist a new entity.
     *
     * @param T $entity
     * @throws OrmException
     */
    public function insert(object $entity, MutationContext $context): void;

    /**
     * Update an existing entity.
     *
     * @param T $entity
     * @throws OrmException
     * @throws OptimisticLockException
     */
    public function update(object $entity, MutationContext $context): void;

    /**
     * Delete an entity (or soft-delete if configured).
     *
     * @param T $entity
     * @throws OrmException
     */
    public function delete(object $entity, MutationContext $context): void;

    /**
     * Count entities matching optional criteria.
     *
     * @param array<string, mixed> $criteria
     */
    public function count(array $criteria = []): int;

    /**
     * Check if an entity exists by primary key.
     */
    public function exists(string|int $id): bool;
}
