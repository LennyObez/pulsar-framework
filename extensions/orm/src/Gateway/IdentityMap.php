<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Gateway;

use Pulsar\Api\Api;

use function count;
use function sprintf;

/**
 * Tracks loaded entities by class + primary key to ensure identity consistency.
 *
 * Within a single unit-of-work (request), the same entity row always
 * maps to the same PHP object reference. This prevents inconsistencies
 * from multiple queries loading the same row into separate objects.
 * @api
 */
#[Api(since: '1.0.0')]
final class IdentityMap
{
    /** @var array<string, object> key = "ClassName#id" */
    private array $entities = [];

    /**
     * Check if an entity is already tracked.
     *
     * @param class-string $class
     */
    public function has(string $class, string|int $id): bool
    {
        return isset($this->entities[self::key($class, $id)]);
    }

    /**
     * Get a tracked entity.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T|null
     */
    public function get(string $class, string|int $id): ?object
    {
        /** @var T|null */
        return $this->entities[self::key($class, $id)] ?? null;
    }

    /**
     * Register an entity in the identity map.
     *
     * @param class-string $class
     */
    public function put(string $class, string|int $id, object $entity): void
    {
        $this->entities[self::key($class, $id)] = $entity;
    }

    /**
     * Remove an entity from the identity map.
     *
     * @param class-string $class
     */
    public function remove(string $class, string|int $id): void
    {
        unset($this->entities[self::key($class, $id)]);
    }

    /**
     * Clear all tracked entities.
     */
    public function clear(): void
    {
        $this->entities = [];
    }

    /**
     * Get the number of tracked entities.
     */
    public function count(): int
    {
        return count($this->entities);
    }

    /**
     * Check if the identity map contains any entity of the given class.
     *
     * @param class-string $class
     * @return list<object>
     */
    public function allOfClass(string $class): array
    {
        $prefix = $class . '#';
        $result = [];

        foreach ($this->entities as $key => $entity) {
            if (str_starts_with($key, $prefix)) {
                $result[] = $entity;
            }
        }

        return $result;
    }

    private static function key(string $class, string|int $id): string
    {
        return sprintf('%s#%s', $class, $id);
    }
}
