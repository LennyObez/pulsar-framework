<?php

declare(strict_types=1);

namespace Pulsar\Resilience;

use Closure;
use Pulsar\Config\CircuitBreakerConfig;
use Pulsar\Resilience\Exception\ResilienceException;
use Throwable;

/**
 * Circuit breaker pattern implementation.
 *
 * Tracks failures and opens the circuit when the failure threshold is exceeded.
 * After a timeout period, allows a limited number of probe requests (half-open state).
 * Resets to closed after sufficient successful probes.
 */
final class CircuitBreaker
{
    private CircuitBreakerState $state = CircuitBreakerState::Closed;
    private int $failureCount = 0;
    private int $successCount = 0;
    private ?float $openedAt = null;

    public function __construct(
        private readonly string $name,
        private readonly int $failureThreshold,
        private readonly int $successThreshold,
        private readonly int $openTimeoutSeconds,
    ) {}

    /**
     * Create a CircuitBreaker from a config DTO.
     */
    public static function fromConfig(string $name, CircuitBreakerConfig $config): self
    {
        return new self(
            name: $name,
            failureThreshold: $config->failureThreshold,
            successThreshold: $config->successThreshold,
            openTimeoutSeconds: $config->openTimeoutSeconds,
        );
    }

    /**
     * Execute the given closure through the circuit breaker.
     *
     * @template T
     * @param Closure(): T $operation
     * @return T
     *
     * @throws ResilienceException If the circuit is open.
     */
    public function execute(Closure $operation): mixed
    {
        if (!$this->isAvailable()) {
            throw ResilienceException::circuitOpen($this->name);
        }

        try {
            $result = $operation();
            $this->recordSuccess();

            return $result;
        } catch (Throwable $e) {
            $this->recordFailure();

            throw $e;
        }
    }

    /**
     * Get the current circuit state.
     */
    public function state(): CircuitBreakerState
    {
        $this->evaluateState();

        return $this->state;
    }

    /**
     * Get the circuit breaker name.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Record a successful operation.
     */
    public function recordSuccess(): void
    {
        $this->evaluateState();

        if ($this->state === CircuitBreakerState::HalfOpen) {
            $this->successCount++;

            if ($this->successCount >= $this->successThreshold) {
                $this->transitionTo(CircuitBreakerState::Closed);
            }
        }

        if ($this->state === CircuitBreakerState::Closed) {
            $this->failureCount = 0;
        }
    }

    /**
     * Record a failed operation.
     */
    public function recordFailure(): void
    {
        $this->evaluateState();

        if ($this->state === CircuitBreakerState::HalfOpen) {
            $this->transitionTo(CircuitBreakerState::Open);

            return;
        }

        if ($this->state === CircuitBreakerState::Closed) {
            $this->failureCount++;

            if ($this->failureCount >= $this->failureThreshold) {
                $this->transitionTo(CircuitBreakerState::Open);
            }
        }
    }

    /**
     * Reset the circuit breaker to closed state.
     */
    public function reset(): void
    {
        $this->transitionTo(CircuitBreakerState::Closed);
    }

    /**
     * Check if the circuit breaker is available to accept requests.
     */
    public function isAvailable(): bool
    {
        $this->evaluateState();

        return $this->state !== CircuitBreakerState::Open;
    }

    /**
     * Get the current failure count.
     */
    public function failureCount(): int
    {
        return $this->failureCount;
    }

    /**
     * Get the current success count (relevant in half-open state).
     */
    public function successCount(): int
    {
        return $this->successCount;
    }

    /**
     * Evaluate whether the state should transition (Open → HalfOpen after timeout).
     */
    private function evaluateState(): void
    {
        if ($this->state === CircuitBreakerState::Open && $this->openedAt !== null) {
            $elapsed = microtime(true) - $this->openedAt;

            if ($elapsed >= $this->openTimeoutSeconds) {
                $this->state = CircuitBreakerState::HalfOpen;
                $this->successCount = 0;
            }
        }
    }

    /**
     * Transition to a new state.
     */
    private function transitionTo(CircuitBreakerState $newState): void
    {
        $this->state = $newState;

        if ($newState === CircuitBreakerState::Open) {
            $this->openedAt = microtime(true);
            $this->successCount = 0;
        } elseif ($newState === CircuitBreakerState::Closed) {
            $this->failureCount = 0;
            $this->successCount = 0;
            $this->openedAt = null;
        }
    }
}
