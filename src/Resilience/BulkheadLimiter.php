<?php

declare(strict_types=1);

namespace Pulsar\Resilience;

use Closure;
use Pulsar\Api\Api;
use Pulsar\Resilience\Exception\ResilienceException;
use Throwable;

/**
 * Semaphore-based concurrency limiter (bulkhead pattern).
 *
 * Prevents one failing dependency from consuming all available capacity
 * by limiting the number of concurrent executions per resource. When the
 * maximum is reached, additional calls are rejected immediately.
 * @api
 */
#[Api(since: '1.0.0')]
final class BulkheadLimiter
{
    private int $active = 0;

    public function __construct(
        private readonly string $resource,
        private readonly int $maxConcurrent,
    ) {}

    /**
     * Execute the operation if a slot is available.
     *
     * @template T
     * @param Closure(): T $operation
     * @return T
     *
     * @throws ResilienceException If the bulkhead is full
     * @throws Throwable If the operation throws
     */
    public function execute(Closure $operation): mixed
    {
        if ($this->active >= $this->maxConcurrent) {
            throw ResilienceException::bulkheadFull($this->resource, $this->maxConcurrent);
        }

        $this->active++;

        try {
            return $operation();
        } finally {
            $this->active--;
        }
    }

    /**
     * Get the number of currently active executions.
     */
    public function activeCount(): int
    {
        return $this->active;
    }

    /**
     * Get the maximum allowed concurrent executions.
     */
    public function maxConcurrent(): int
    {
        return $this->maxConcurrent;
    }

    /**
     * Get the number of available slots.
     */
    public function availableSlots(): int
    {
        return $this->maxConcurrent - $this->active;
    }

    /**
     * Get the resource name this limiter protects.
     */
    public function resource(): string
    {
        return $this->resource;
    }
}
