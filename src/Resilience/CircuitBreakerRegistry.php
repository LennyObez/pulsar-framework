<?php

declare(strict_types=1);

namespace Pulsar\Resilience;

use Pulsar\Config\CircuitBreakerConfig;

/**
 * Registry of named circuit breakers.
 *
 * Provides create-or-return semantics so callers get the same breaker
 * for a given name throughout the application lifecycle.
 */
final class CircuitBreakerRegistry
{
    /** @var array<string, CircuitBreaker> */
    private array $breakers = [];

    public function __construct(
        private readonly CircuitBreakerConfig $defaultConfig,
    ) {}

    /**
     * Get or create a circuit breaker by name.
     */
    public function get(string $name): CircuitBreaker
    {
        if (!isset($this->breakers[$name])) {
            $this->breakers[$name] = CircuitBreaker::fromConfig($name, $this->defaultConfig);
        }

        return $this->breakers[$name];
    }

    /**
     * Check if a breaker with the given name exists.
     */
    public function has(string $name): bool
    {
        return isset($this->breakers[$name]);
    }

    /**
     * Get all registered circuit breakers.
     *
     * @return array<string, CircuitBreaker>
     */
    public function all(): array
    {
        return $this->breakers;
    }

    /**
     * Reset a specific circuit breaker.
     */
    public function reset(string $name): void
    {
        if (isset($this->breakers[$name])) {
            $this->breakers[$name]->reset();
        }
    }

    /**
     * Reset all circuit breakers.
     */
    public function resetAll(): void
    {
        foreach ($this->breakers as $breaker) {
            $breaker->reset();
        }
    }
}
