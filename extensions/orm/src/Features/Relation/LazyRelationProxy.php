<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Relation;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Orm\Contracts\EntityHydratorInterface;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Domain\RelationMetadata;
use Pulsar\Extension\Orm\Domain\RelationType;
use Pulsar\Extension\Orm\Features\Query\SelectBuilder;
use Traversable;

use function array_values;
use function count;

/**
 * Transparent proxy that lazily loads related entities on first access.
 *
 * For HasMany/BelongsToMany relations, this proxy behaves like an
 * iterable collection. For HasOne/BelongsTo, use getFirst().
 *
 * @template T of object
 * @implements IteratorAggregate<int, T>
 * @implements ArrayAccess<int, T>
 * @api
 */
#[Api(since: '1.0.0')]
final class LazyRelationProxy implements IteratorAggregate, Countable, ArrayAccess
{
    /** @var list<T>|null */
    private ?array $loaded = null;

    /**
     * @param string|int $parentId Primary key value of the owning entity
     */
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly MetadataRegistryInterface $metadataRegistry,
        private readonly EntityHydratorInterface $hydrator,
        private readonly RelationMetadata $relation,
        private readonly string|int $parentId,
        private readonly ?string $parentClass = null,
    ) {}

    /**
     * Force-load the related entities and return them.
     *
     * @return list<T>
     */
    public function load(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        $targetMetadata = $this->metadataRegistry->get($this->relation->targetEntity);
        $builder = new SelectBuilder($this->connection);
        $builder->forEntity($this->relation->targetEntity, $targetMetadata, $this->hydrator);

        match ($this->relation->type) {
            RelationType::HasOne, RelationType::HasMany => $builder->where(
                $this->relation->foreignKey,
                $this->parentId,
            ),
            RelationType::BelongsTo => $builder->where(
                $this->relation->localKey,
                $this->parentId,
            ),
            RelationType::MorphMany => (function () use ($builder): void {
                $builder->where($this->relation->morphTypeColumn ?? '', $this->parentClass ?? '');
                $builder->where($this->relation->morphIdColumn ?? '', $this->parentId);
            })(),
            default => null,
        };

        /** @var list<T> $entities */
        $entities = $builder->getEntities();
        $this->loaded = $entities;

        return $this->loaded;
    }

    /**
     * Get the first related entity (for HasOne/BelongsTo).
     *
     * @return T|null
     */
    public function getFirst(): ?object
    {
        $loaded = $this->load();

        return $loaded[0] ?? null;
    }

    public function isLoaded(): bool
    {
        return $this->loaded !== null;
    }

    public function count(): int
    {
        return count($this->load());
    }

    /**
     * @return Traversable<int, T>
     */
    public function getIterator(): Traversable
    {
        yield from $this->load();
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->load()[$offset]);
    }

    /**
     * @return T|null
     */
    public function offsetGet(mixed $offset): ?object
    {
        return $this->load()[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        // Immutable: lazy proxy is read-only
    }

    public function offsetUnset(mixed $offset): void
    {
        // Immutable: lazy proxy is read-only
    }

    /**
     * @return list<T>
     */
    public function toArray(): array
    {
        return array_values($this->load());
    }
}
