<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Persistence;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Audit\MutationContext;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Orm\Contracts\EntityHydratorInterface;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Contracts\RepositoryInterface;
use Pulsar\Extension\Orm\Domain\FetchPlan;
use Pulsar\Extension\Orm\Exception\EntityNotFoundException;
use Pulsar\Extension\Orm\Features\Query\SelectBuilder;
use Pulsar\Extension\Orm\Features\Tenancy\TenantScopeApplier;

/**
 * Generic repository implementation for any entity type.
 *
 * @template T of object
 * @implements RepositoryInterface<T>
 */
#[Internal]
final readonly class GenericRepository implements RepositoryInterface
{
    public function __construct(
        private ConnectionInterface $connection,
        private MetadataRegistryInterface $metadataRegistry,
        private EntityHydratorInterface $hydrator,
        private AuditingPersister $persister,
        /** @var class-string<T> */
        private string $entityClass,
        // Null in a single-tenant app (no TenantScope bound); non-null wires the
        // read-side tenant filter so find()/findBy()/count()/exists() cannot
        // return another tenant's rows.
        private ?TenantScopeApplier $tenantScopeApplier = null,
    ) {}

    /** @return T|null */
    #[Override]
    public function find(string|int $id, ?FetchPlan $fetchPlan = null): ?object
    {
        $metadata = $this->metadataRegistry->get($this->entityClass);
        $builder = $this->query();

        if ($fetchPlan !== null) {
            $builder->withFetchPlan($fetchPlan);
        }

        $builder->where($metadata->primaryKey->columnName, $id);

        /** @var T|null */
        return $builder->firstEntity();
    }

    #[Override]
    public function findOrFail(string|int $id, ?FetchPlan $fetchPlan = null): object
    {
        $entity = $this->find($id, $fetchPlan);

        if ($entity === null) {
            throw EntityNotFoundException::notFound($this->entityClass, $id);
        }

        return $entity;
    }

    /** @return list<T> */
    #[Override]
    public function findBy(array $criteria = [], ?FetchPlan $fetchPlan = null): array
    {
        $builder = $this->query();

        if ($fetchPlan !== null) {
            $builder->withFetchPlan($fetchPlan);
        }

        /** @var mixed $value */
        foreach ($criteria as $column => $value) {
            $builder->where((string) $column, $value);
        }

        /** @var list<T> */
        return $builder->getEntities();
    }

    /** @return T|null */
    #[Override]
    public function findOneBy(array $criteria, ?FetchPlan $fetchPlan = null): ?object
    {
        $builder = $this->query();

        if ($fetchPlan !== null) {
            $builder->withFetchPlan($fetchPlan);
        }

        /** @var mixed $value */
        foreach ($criteria as $column => $value) {
            $builder->where((string) $column, $value);
        }

        /** @var T|null */
        return $builder->firstEntity();
    }

    #[Override]
    public function query(): SelectBuilder
    {
        $metadata = $this->metadataRegistry->get($this->entityClass);
        $builder = new SelectBuilder($this->connection);
        $builder->forEntity($this->entityClass, $metadata, $this->hydrator);

        // Constrain every read to the active tenant. query() is the single
        // chokepoint for find()/findBy()/findOneBy()/count()/exists(), so the
        // filter applies uniformly; the applier is a no-op for non-tenant-scoped
        // entities and when no tenant context is active.
        $this->tenantScopeApplier?->apply($builder, $metadata);

        return $builder;
    }

    #[Override]
    public function insert(object $entity, MutationContext $context): void
    {
        $this->persister->insert($entity, $context);
    }

    #[Override]
    public function update(object $entity, MutationContext $context): void
    {
        $this->persister->update($entity, $context);
    }

    #[Override]
    public function delete(object $entity, MutationContext $context): void
    {
        $this->persister->delete($entity, $context);
    }

    #[Override]
    public function bulkInsert(array $entities, MutationContext $context): void
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

    #[Override]
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

    #[Override]
    public function count(array $criteria = []): int
    {
        $builder = $this->query();

        /** @var mixed $value */
        foreach ($criteria as $column => $value) {
            $builder->where((string) $column, $value);
        }

        return $builder->aggregate()->count();
    }

    #[Override]
    public function exists(string|int $id): bool
    {
        $metadata = $this->metadataRegistry->get($this->entityClass);
        $builder = $this->query();
        $builder->where($metadata->primaryKey->columnName, $id);

        return $builder->aggregate()->count() > 0;
    }
}
