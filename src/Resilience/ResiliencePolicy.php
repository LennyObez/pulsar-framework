<?php

declare(strict_types=1);

namespace Pulsar\Resilience;

use Closure;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Resilience\Exception\ResilienceException;
use Throwable;

use function microtime;

/**
 * Composable resilience policy combining retry, circuit breaker, timeout, and bulkhead.
 *
 * Inspired by Polly (.NET) and resilience4j (Java). Wraps an operation with
 * multiple resilience strategies applied in order:
 *
 *   1. Bulkhead (concurrency limit): outermost, controls admission
 *   2. Timeout: cancels if operation exceeds time budget
 *   3. Circuit breaker: fast-fail on known-bad dependencies
 *   4. Retry: innermost, retries transient failures
 *
 * Each layer is optional; only configured strategies are applied.
 */
#[Api(since: '1.0.0')]
final readonly class ResiliencePolicy
{
    private function __construct(
        private ?RetryPolicy $retryPolicy = null,
        private ?CircuitBreaker $circuitBreaker = null,
        private ?int $timeoutMs = null,
        private ?BulkheadLimiter $bulkhead = null,
    ) {}

    /**
     * Create a new empty resilience policy.
     */
    #[NoDiscard]
    public static function create(): self
    {
        return new self();
    }

    /**
     * Add retry with exponential backoff.
     */
    #[NoDiscard]
    public function withRetry(RetryPolicy $retryPolicy): self
    {
        return clone($this, ['retryPolicy' => $retryPolicy]);
    }

    /**
     * Add circuit breaker protection.
     */
    #[NoDiscard]
    public function withCircuitBreaker(CircuitBreaker $circuitBreaker): self
    {
        return clone($this, ['circuitBreaker' => $circuitBreaker]);
    }

    /**
     * Add a timeout in milliseconds.
     */
    #[NoDiscard]
    public function withTimeout(int $timeoutMs): self
    {
        return clone($this, ['timeoutMs' => $timeoutMs]);
    }

    /**
     * Add bulkhead (concurrency) limiting.
     */
    #[NoDiscard]
    public function withBulkhead(BulkheadLimiter $bulkhead): self
    {
        return clone($this, ['bulkhead' => $bulkhead]);
    }

    /**
     * Execute the given operation through all configured resilience layers.
     *
     * @template T
     * @param Closure(): T $operation
     * @return T
     *
     * @throws ResilienceException If any resilience layer rejects the call
     * @throws Throwable If the operation throws and retries are exhausted
     */
    public function execute(Closure $operation): mixed
    {
        $wrapped = $operation;

        // Layer 4 (innermost): Retry
        if ($this->retryPolicy !== null) {
            $retryPolicy = $this->retryPolicy;
            $inner = $wrapped;
            $wrapped = static function () use ($retryPolicy, $inner): mixed {
                $result = $retryPolicy->execute($inner);

                if (!$result->succeeded) {
                    throw ResilienceException::retryExhausted(
                        'resilience_policy',
                        $result->attempts,
                        $result->lastException,
                    );
                }

                return $result->result;
            };
        }

        // Layer 3: Circuit Breaker
        if ($this->circuitBreaker !== null) {
            $cb = $this->circuitBreaker;
            $inner = $wrapped;
            $wrapped = static fn(): mixed => $cb->execute($inner);
        }

        // Layer 2: Timeout
        if ($this->timeoutMs !== null) {
            $timeoutMs = $this->timeoutMs;
            $inner = $wrapped;
            $wrapped = static function () use ($inner, $timeoutMs): mixed {
                $start = microtime(true);
                $result = $inner();
                $elapsed = (microtime(true) - $start) * 1000.0;

                if ($elapsed > (float) $timeoutMs) {
                    throw ResilienceException::timeout('resilience_policy', $timeoutMs, (int) $elapsed);
                }

                return $result;
            };
        }

        // Layer 1 (outermost): Bulkhead
        if ($this->bulkhead !== null) {
            return $this->bulkhead->execute($wrapped);
        }

        return $wrapped();
    }
}
