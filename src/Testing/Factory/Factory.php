<?php

declare(strict_types=1);

namespace Pulsar\Testing\Factory;

use Pulsar\Api\Api;

use function array_merge;

/**
 * Base class for entity factories: define defaults, apply states, create entities.
 *
 * Usage:
 *   class UserFactory extends Factory
 *   {
 *       protected function definition(): array
 *       {
 *           return ['name' => 'John', 'email' => 'john@test.com'];
 *       }
 *
 *       public function admin(): static
 *       {
 *           return $this->state(['role' => 'admin']);
 *       }
 *   }
 *
 *   $user = UserFactory::new()->admin()->make();
 *
 * @phpstan-consistent-constructor
 * @psalm-consistent-constructor
 */
#[Api(since: '1.0.0')]
abstract class Factory
{
    /** @var list<array<string, mixed>> */
    private array $states = [];

    private int $count = 1;

    /** @var array<string, Sequence> */
    private array $sequences = [];

    /**
     * Create a new factory instance.
     *
     * @return static
     */
    public static function new(): static
    {
        return new static();
    }

    /**
     * Define the default attribute values.
     *
     * @return array<string, mixed>
     */
    abstract protected function definition(): array;

    /**
     * Apply a state (attribute overrides) to the factory.
     *
     * @param array<string, mixed> $attributes
     *
     * @return static
     */
    public function state(array $attributes): static
    {
        return clone($this, ['states' => [...$this->states, $attributes]]);
    }

    /**
     * Set the number of entities to create.
     *
     * @param int $count Number of entities to generate
     *
     * @return static
     */
    public function count(int $count): static
    {
        return clone($this, ['count' => $count]);
    }

    /**
     * Register a sequence for a specific attribute.
     *
     * @param string $attribute Attribute name to apply the sequence to
     * @param Sequence $sequence Sequence generator
     *
     * @return static
     */
    public function sequence(string $attribute, Sequence $sequence): static
    {
        return clone($this, ['sequences' => [...$this->sequences, $attribute => $sequence]]);
    }

    /**
     * Make entity attribute arrays without persistence.
     *
     * Returns a single attribute array when count is 1, or a list of arrays when count > 1.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public function make(array $overrides = []): array
    {
        if ($this->count === 1) {
            return $this->resolveAttributes($overrides);
        }

        $results = [];

        for ($i = 0; $i < $this->count; $i++) {
            $results[] = $this->resolveAttributes($overrides);
        }

        return $results;
    }

    /**
     * Resolve all attributes by merging definition + states + sequences + overrides.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function resolveAttributes(array $overrides): array
    {
        $attributes = $this->definition();

        foreach ($this->states as $state) {
            $attributes = array_merge($attributes, $state);
        }

        foreach ($this->sequences as $attribute => $sequence) {
            $attributes[$attribute] = $sequence();
        }

        return array_merge($attributes, $overrides);
    }
}
