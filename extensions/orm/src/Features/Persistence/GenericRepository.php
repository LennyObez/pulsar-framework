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
        private readonly ConnectionInterface $connection,
        private readonly MetadataRegistryInterface $metadataRegistry,
        private readonly EntityHydratorInterface $hydrator,
        private readonly AuditingPersister $persister,
        /** @var class-string<T> */
        private readonly string $entityClass,
    ) {}

    #[Override]
    public function find(string|int $id, ?FetchPlan $fetchPlan = null): ?object
    {
        $metadata = $this->metadataRegistry->get($this->entityClass);
        $builder = $this->query();

        if ($fetchPlan !== null) {
            $builder->withFetchPlan($fetchPlan);
        }

        $builder->where($metadata->primaryKey->columnName, $id);

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

    #[Override]
    public function findBy(array $criteria = [], ?FetchPlan $fetchPlan = null): array
    {
        $builder = $this->query();

        if ($fetchPlan !== null) {
            $builder->withFetchPlan($fetchPlan);
        }

        foreach ($criteria as $column => $value) {
            $builder->where($column, $value);
        }

        return $builder->getEntities();
    }

    #[Override]
    public function findOneBy(array $criteria, ?FetchPlan $fetchPlan = null): ?object
    {
        $builder = $this->query();

        if ($fetchPlan !== null) {
            $builder->withFetchPlan($fetchPlan);
        }

        foreach ($criteria as $column => $value) {
            $builder->where($column, $value);
        }

        return $builder->firstEntity();
    }

    #[Override]
    public function query(): SelectBuilder
    {
        $metadata = $this->metadataRegistry->get($this->entityClass);
        $builder = new SelectBuilder($this->connection);
        $builder->forEntity($this->entityClass, $metadata, $this->hydrator);

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
    public function count(array $criteria = []): int
    {
        $builder = $this->query();

        foreach ($criteria as $column => $value) {
            $builder->where($column, $value);
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
