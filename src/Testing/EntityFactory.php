<?php

declare(strict_types=1);

namespace Pulsar\Testing;

use NoDiscard;
use Pulsar\Api\Api;

use function array_replace;

/**
 * Base class for entity test factories.
 *
 * Provides a fluent builder API for creating entity instances in tests.
 * Subclasses define default attribute values in `defaults()`, and
 * tests override specific values via `with()` or `state()`.
 *
 * Usage:
 *   $user = UserFactory::new()->with(['email' => 'test@example.com'])->create();
 *   $users = UserFactory::new()->count(5)->create();
 *
 * @template T of object
 *
 * @phpstan-consistent-constructor
 * @psalm-consistent-constructor
 * @api
 */
#[Api(since: '1.0.0')]
abstract class EntityFactory
{
    /** @var array<string, mixed> */
    private array $overrides = [];

    private int $count = 1;

    /** @var list<callable(array<string, mixed>): array<string, mixed>> */
    private array $states = [];

    /**
     * Create a new factory instance.
     *
     * PHPStan cannot infer the template type through late static binding,
     * but subclasses always return the correctly-typed instance.
     *
     * @return static
     */
    #[NoDiscard]
    public static function new(): static
    {
        // @phpstan-ignore return.type (template T cannot be inferred through late static binding)
        return new static();
    }

    /**
     * Define default attribute values.
     *
     * @return array<string, mixed>
     */
    abstract protected function defaults(): array;

    /**
     * Build the entity from the given attributes.
     *
     * @param array<string, mixed> $attributes
     * @return T
     */
    abstract protected function build(array $attributes): object;

    /**
     * Override specific attribute values.
     *
     * @param array<string, mixed> $overrides
     * @return static
     */
    #[NoDiscard]
    public function with(array $overrides): static
    {
        $clone = clone $this;
        $clone->overrides = array_replace($this->overrides, $overrides);

        return $clone;
    }

    /**
     * Apply a state transformation.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $state
     * @return static
     */
    #[NoDiscard]
    public function state(callable $state): static
    {
        $clone = clone $this;
        $clone->states = [...$this->states, $state];

        return $clone;
    }

    /**
     * Set the number of entities to create.
     *
     * @return static
     */
    #[NoDiscard]
    public function count(int $count): static
    {
        $clone = clone $this;
        $clone->count = $count;

        return $clone;
    }

    /**
     * Create a single entity or a list of entities.
     *
     * @return T|list<T>
     */
    #[NoDiscard]
    public function create(): object|array
    {
        if ($this->count === 1) {
            return $this->buildSingle();
        }

        $entities = [];

        for ($i = 0; $i < $this->count; $i++) {
            $entities[] = $this->buildSingle();
        }

        return $entities;
    }

    /**
     * Create a single entity without persisting (alias for create in base class).
     *
     * @return T
     */
    #[NoDiscard]
    public function make(): object
    {
        return $this->buildSingle();
    }

    /**
     * Get the resolved attributes without building the entity.
     *
     * @return array<string, mixed>
     */
    #[NoDiscard]
    public function resolveAttributes(): array
    {
        $attributes = array_replace($this->defaults(), $this->overrides);

        foreach ($this->states as $state) {
            $attributes = $state($attributes);
        }

        return $attributes;
    }

    /**
     * @return T
     */
    private function buildSingle(): object
    {
        return $this->build($this->resolveAttributes());
    }
}
