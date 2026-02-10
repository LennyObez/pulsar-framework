<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Gateway;

use Pulsar\Api\Api;
use Pulsar\Audit\MutationContext;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Orm\Contracts\ColumnEncryptorInterface;
use Pulsar\Extension\Orm\Contracts\EntityHydratorInterface;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Contracts\RepositoryInterface;
use Pulsar\Extension\Orm\Contracts\SchemaBuilderInterface;
use Pulsar\Extension\Orm\Contracts\TransactionManagerInterface;
use Pulsar\Extension\Orm\Domain\EntityId;
use Pulsar\Extension\Orm\Domain\FetchPlan;
use Pulsar\Extension\Orm\Features\Persistence\AuditingPersister;
use Pulsar\Extension\Orm\Features\Persistence\GenericRepository;
use Pulsar\Extension\Orm\Features\Query\SelectBuilder;
use Pulsar\Extension\Orm\Features\Schema\SchemaBuilder;

/**
 * Central gateway for ORM operations.
 *
 * Provides access to repositories, query builders, schema management,
 * and transaction control. This is the primary entry point for application
 * code interacting with the ORM.
 */
#[Api(since: '1.0.0')]
final class EntityManager
{
    /** @var array<class-string, RepositoryInterface<object>> */
    private array $repositories = [];

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly MetadataRegistryInterface $metadataRegistry,
        private readonly EntityHydratorInterface $hydrator,
        private readonly AuditingPersister $persister,
        private readonly TransactionManagerInterface $transactionManager,
    ) {}

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
            );
        }

        /** @var RepositoryInterface<T> */
        return $this->repositories[$entityClass];
    }

    /**
     * Find an entity by primary key.
     *
     * @template T of object
     * @param class-string<T> $entityClass
     * @return T|null
     */
    public function find(string $entityClass, string|int $id, ?FetchPlan $fetchPlan = null): ?object
    {
        return $this->repository($entityClass)->find($id, $fetchPlan);
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
     * Create a query builder for the given entity.
     *
     * @param class-string $entityClass
     */
    public function query(string $entityClass): SelectBuilder
    {
        return $this->repository($entityClass)->query();
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
}
