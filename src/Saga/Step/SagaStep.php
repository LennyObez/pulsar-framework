<?php

declare(strict_types=1);

namespace Pulsar\Saga\Step;

use Closure;
use Pulsar\Api\Api;

/**
 * Immutable definition of a single saga step.
 *
 * Each step declares a forward action and (optionally) a compensation action
 * with idempotency keys, retry policies, and an irreversibility flag.
 *
 * Compensation is NOT rollback; each step explicitly declares compensation
 * semantics. Irreversible steps (e.g., sending emails) cannot be compensated;
 * the saga handles this via logging and operator alerts.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SagaStep
{
    /**
     * @param non-empty-string                          $name
     * @param class-string                              $forwardAction           Action class for the forward direction
     * @param (Closure(array<string, mixed>): string)|null $forwardIdempotencyKey   Generates idempotency key from context
     * @param class-string|null                         $compensationAction      Action class for compensation
     * @param (Closure(array<string, mixed>): string)|null $compensationIdempotencyKey Generates compensation idempotency key
     * @param bool                                      $irreversible            If true, this step cannot be compensated
     */
    public function __construct(
        public string $name,
        public string $forwardAction,
        public ?Closure $forwardIdempotencyKey = null,
        public ?string $compensationAction = null,
        public ?Closure $compensationIdempotencyKey = null,
        public RetryPolicy $retryPolicy = new RetryPolicy(),
        public RetryPolicy $compensationRetryPolicy = new RetryPolicy(),
        public bool $irreversible = false,
    ) {}

    /**
     * Check if this step has a compensation action defined.
     */
    public function hasCompensation(): bool
    {
        return $this->compensationAction !== null && !$this->irreversible;
    }
}
