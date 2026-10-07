<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Domain;

use NoDiscard;
use Pulsar\Api\Api;

use function array_key_exists;
use function array_keys;
use function array_merge;

/**
 * Declarative fetch plan for eager relation loading.
 *
 * No lazy loading: all relations must be declared upfront via FetchPlan.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class FetchPlan
{
    /**
     * @param array<string, self|null> $relations Map of relation name => nested FetchPlan (or null for flat load)
     */
    public function __construct(
        private array $relations = [],
    ) {}

    /**
     * Create a fetch plan that loads the given relations.
     *
     * @param list<string> $relations
     */
    #[NoDiscard]
    public static function with(array $relations): self
    {
        $map = [];
        foreach ($relations as $relation) {
            $map[$relation] = null;
        }

        return new self($map);
    }

    /**
     * Create a fetch plan with nested fetch plans.
     *
     * @param array<string, self|null> $relations
     */
    #[NoDiscard]
    public static function withNested(array $relations): self
    {
        return new self($relations);
    }

    /**
     * Create an empty fetch plan (no relations loaded).
     */
    #[NoDiscard]
    public static function none(): self
    {
        return new self();
    }

    public function has(string $relation): bool
    {
        return isset($this->relations[$relation]) || array_key_exists($relation, $this->relations);
    }

    public function nested(string $relation): ?self
    {
        return $this->relations[$relation] ?? null;
    }

    /**
     * @return list<string>
     */
    public function relationNames(): array
    {
        return array_keys($this->relations);
    }

    /**
     * Merge another fetch plan into this one.
     */
    #[NoDiscard]
    public function merge(self $other): self
    {
        return new self(array_merge($this->relations, $other->relations));
    }

    public function isEmpty(): bool
    {
        return $this->relations === [];
    }
}
