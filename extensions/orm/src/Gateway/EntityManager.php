<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Gateway;

use Pulsar\Api\Api;
use Pulsar\Audit\MutationContext;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Orm\Contracts\EntityHydratorInterface;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Contracts\RepositoryInterface;
use Pulsar\Extension\Orm\Contracts\SchemaBuilderInterface;
use Pulsar\Extension\Orm\Contracts\TransactionManagerInterface;
use Pulsar\Extension\Orm\Domain\FetchPlan;
use Pulsar\Extension\Orm\Features\Persistence\AuditingPersister;
use Pulsar\Extension\Orm\Features\Persistence\GenericRepository;
use Pulsar\Extension\Orm\Features\Query\SelectBuilder;
use Pulsar\Extension\Orm\Features\Schema\SchemaBuilder;
use Pulsar\Extension\Orm\Features\Tenancy\TenantScopeApplier;

use function assert;

/**
 * Central gateway for ORM operations.
 *
 * Provides access to repositories, query builders, schema management,
 * and transaction control. This is the primary entry point for application
 * code interacting with the ORM.
 * @api
 */
#[Api(since: '1.0.0')]
final class EntityManager
{
    /** @var array<class-string, RepositoryInterface<object>> */
    private array $repositories = [];

    private readonly IdentityMap $identityMap;

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly MetadataRegistryInterface $metadataRegistry,
        private readonly EntityHydratorInterface $hydrator,
        private readonly AuditingPersister $persister,
        private readonly TransactionManagerInterface $transactionManager,
        // Wired only in multi-tenant apps (a TenantScope is bound); passed to
        // every repository so reads are tenant-scoped. Null = single-tenant.
        private readonly ?TenantScopeApplier $tenantScopeApplier = null,
    ) {
        $this->identityMap = new IdentityMap();
    }

    /**
     * Get a repository for the given entity class.
     *
     * @template T of object
     * @param class-string<T> $entityClass
     * @return RepositoryInterface<T>
     */
    public function repository(string $entityClass): RepositoryInterface
    {
        if (!isset($this->repositories[$entityClass])) {
            $this->repositories[$entityClass] = new GenericRepository(
                $this->connection,
                $this->metadataRegistry,
                $this->hydrator,
                $this->persister,
                $entityClass,
                $this->tenantScopeApplier,
            );
        }

        /** @var RepositoryInterface<T> */
        return $this->repositories[$entityClass];
    }

    /**
     * Find an entity by primary key.
     *
     * Checks the identity map first to avoid redundant queries and ensure
     * that the same row always maps to the same PHP object reference within
     * a single unit-of-work. Falls back to a database query if the entity
     * is not yet tracked.
     *
     * @template T of object
     * @param class-string<T> $entityClass
     * @return T|null
     */
    public function find(string $entityClass, string|int $id, ?FetchPlan $fetchPlan = null): ?object
    {
        // Check identity map first; return cached reference if available
        if ($fetchPlan === null && $this->identityMap->has($entityClass, $id)) {
            /** @var T|null */
            return $this->identityMap->get($entityClass, $id);
        }

        $entity = $this->repository($entityClass)->find($id, $fetchPlan);

        // Track the loaded entity in the identity map
        if ($entity !== null) {
            $this->identityMap->put($entityClass, $id, $entity);
        }

        return $entity;
    }

    /**
     * Persist a new entity.
     */
    public function persist(object $entity, MutationContext $context): void
    {
        $this->persister->insert($entity, $context);
    }

    /**
     * Update an existing entity.
     */
    public function update(object $entity, MutationContext $context): void
    {
        $this->persister->update($entity, $context);
    }

    /**
     * Remove an entity.
     */
    public function remove(object $entity, MutationContext $context): void
    {
        $this->persister->delete($entity, $context);
    }

    /**
     * Persist multiple entities in a single transaction.
     *
     * @param list<object> $entities
     */
    public function bulkPersist(array $entities, MutationContext $context): void
    {
        if ($entities === []) {
            return;
        }

        $this->connection->transaction(function () use ($entities, $context): void {
            foreach ($entities as $entity) {
                $this->persister->insert($entity, $context);
            }
        });
    }

    /**
     * Update multiple entities in a single transaction.
     *
     * @param list<object> $entities
     */
    public function bulkUpdate(array $entities, MutationContext $context): void
    {
        if ($entities === []) {
            return;
        }

        $this->connection->transaction(function () use ($entities, $context): void {
            foreach ($entities as $entity) {
                $this->persister->update($entity, $context);
            }
        });
    }

    /**
     * Create a query builder for the given entity.
     *
     * @param class-string $entityClass
     */
    public function query(string $entityClass): SelectBuilder
    {
        $builder = $this->repository($entityClass)->query();
        assert($builder instanceof SelectBuilder);

        return $builder;
    }

    /**
     * Create a raw query builder (no entity binding).
     */
    public function rawQuery(): SelectBuilder
    {
        return new SelectBuilder($this->connection);
    }

    /**
     * Execute a callback within a transaction.
     *
     * @template T
     * @param callable(ConnectionInterface): T $callback
     * @return T
     */
    public function transactional(callable $callback): mixed
    {
        return $this->transactionManager->transactional($callback);
    }

    /**
     * Get the transaction manager.
     */
    public function transactions(): TransactionManagerInterface
    {
        return $this->transactionManager;
    }

    /**
     * Get a schema builder for DDL operations.
     */
    public function schema(): SchemaBuilderInterface
    {
        return new SchemaBuilder($this->connection);
    }

    /**
     * Get the metadata registry.
     */
    public function metadata(): MetadataRegistryInterface
    {
        return $this->metadataRegistry;
    }

    /**
     * Get the underlying database connection.
     */
    public function connection(): ConnectionInterface
    {
        return $this->connection;
    }

    /**
     * Get the identity map.
     */
    public function identityMap(): IdentityMap
    {
        return $this->identityMap;
    }

    /**
     * Clear the identity map and repository cache.
     *
     * Call this between requests in persistent workers.
     */
    public function clear(): void
    {
        $this->identityMap->clear();
        $this->repositories = [];
    }
}
