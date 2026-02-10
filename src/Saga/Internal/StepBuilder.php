<?php

declare(strict_types=1);

namespace Pulsar\Saga\Internal;

use Closure;
use Pulsar\Api\Internal;
use Pulsar\Saga\Step\RetryPolicy;

/**
 * Mutable builder state for a single saga step being constructed.
 *
 * Used internally by SagaDefinitionBuilder during fluent step construction.
 */
#[Internal(reason: 'Builder state used only by SagaDefinitionBuilder')]
final class StepBuilder
{
    public string $forwardAction = '';

    /** @var (Closure(array<string, mixed>): string)|null */
    public ?Closure $forwardIdempotencyKey = null;

    /** @var class-string|null */
    public ?string $compensationAction = null;

    /** @var (Closure(array<string, mixed>): string)|null */
    public ?Closure $compensationIdempotencyKey = null;

    public RetryPolicy $retryPolicy;

    public RetryPolicy $compensationRetryPolicy;

    public bool $irreversible = false;

    /** @param non-empty-string $name */
    public function __construct(
        public readonly string $name,
    ) {
        $this->retryPolicy = new RetryPolicy();
        $this->compensationRetryPolicy = new RetryPolicy();
    }
}
