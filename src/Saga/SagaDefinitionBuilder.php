<?php

declare(strict_types=1);

namespace Pulsar\Saga;

use Closure;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Saga\Exception\SagaException;
use Pulsar\Saga\Internal\StepBuilder;
use Pulsar\Saga\Step\BackoffStrategy;
use Pulsar\Saga\Step\RetryPolicy;
use Pulsar\Saga\Step\SagaStep;

/**
 * Fluent builder for constructing saga definitions.
 *
 * Usage:
 *
 *     $saga = SagaDefinitionBuilder::create('order_fulfillment')
 *         ->step('charge_payment')
 *             ->forward(ChargePaymentAction::class)
 *             ->forwardIdempotencyKey(fn($ctx) => "charge-{$ctx['orderId']}")
 *             ->compensate(RefundPaymentAction::class)
 *             ->compensationIdempotencyKey(fn($ctx) => "refund-{$ctx['orderId']}")
 *             ->retryPolicy(maxAttempts: 3, backoff: 'exponential')
 *         ->step('send_confirmation')
 *             ->forward(SendConfirmationAction::class)
 *             ->irreversible()
 *         ->build();
 */
#[Api(since: '1.0.0')]
final class SagaDefinitionBuilder
{
    /** @var array<string, SagaStep> */
    private array $steps = [];

    /** @var array<string, mixed> */
    private array $metadata = [];

    private ?StepBuilder $currentStep = null;

    /** @param non-empty-string $name */
    private function __construct(
        private readonly string $name,
    ) {}

    /**
     * Start building a new saga definition.
     *
     * @param non-empty-string $name
     */
    #[NoDiscard]
    public static function create(string $name): self
    {
        return new self($name);
    }

    /**
     * Begin defining a new step.
     *
     * If a previous step was being built, it is finalized and added first.
     *
     * @param non-empty-string $name
     */
    public function step(string $name): self
    {
        $this->finalizeCurrentStep();
        $this->currentStep = new StepBuilder($name);

        return $this;
    }

    /**
     * Set the forward action class for the current step.
     *
     * @param class-string $actionClass
     */
    public function forward(string $actionClass): self
    {
        $this->requireCurrentStep()->forwardAction = $actionClass;

        return $this;
    }

    /**
     * Set the forward idempotency key generator for the current step.
     *
     * @param Closure(array<string, mixed>): string $keyGenerator
     */
    public function forwardIdempotencyKey(Closure $keyGenerator): self
    {
        $this->requireCurrentStep()->forwardIdempotencyKey = $keyGenerator;

        return $this;
    }

    /**
     * Set the compensation action class for the current step.
     *
     * @param class-string $actionClass
     */
    public function compensate(string $actionClass): self
    {
        $this->requireCurrentStep()->compensationAction = $actionClass;

        return $this;
    }

    /**
     * Set the compensation idempotency key generator for the current step.
     *
     * @param Closure(array<string, mixed>): string $keyGenerator
     */
    public function compensationIdempotencyKey(Closure $keyGenerator): self
    {
        $this->requireCurrentStep()->compensationIdempotencyKey = $keyGenerator;

        return $this;
    }

    /**
     * Set the retry policy for the forward direction of the current step.
     *
     * @param int<1, max> $maxAttempts
     * @param int<0, max> $initialDelayMs
     */
    public function retryPolicy(
        int $maxAttempts = 3,
        BackoffStrategy $backoff = BackoffStrategy::Exponential,
        int $initialDelayMs = 100,
    ): self {
        $this->requireCurrentStep()->retryPolicy = new RetryPolicy($maxAttempts, $backoff, $initialDelayMs);

        return $this;
    }

    /**
     * Set the retry policy for the compensation direction of the current step.
     *
     * @param int<1, max> $maxAttempts
     * @param int<0, max> $initialDelayMs
     */
    public function compensationRetryPolicy(
        int $maxAttempts = 5,
        BackoffStrategy $backoff = BackoffStrategy::Exponential,
        int $initialDelayMs = 100,
    ): self {
        $this->requireCurrentStep()->compensationRetryPolicy = new RetryPolicy($maxAttempts, $backoff, $initialDelayMs);

        return $this;
    }

    /**
     * Mark the current step as irreversible (cannot be compensated).
     */
    public function irreversible(): self
    {
        $this->requireCurrentStep()->irreversible = true;

        return $this;
    }

    /**
     * Set arbitrary metadata on the saga definition.
     *
     * @param array<string, mixed> $metadata
     */
    public function metadata(array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * Build and validate the saga definition.
     *
     * @throws SagaException If the definition is invalid
     */
    #[NoDiscard]
    public function build(): SagaDefinition
    {
        $this->finalizeCurrentStep();

        $definition = new SagaDefinition(
            name: $this->name,
            steps: $this->steps,
            metadata: $this->metadata,
        );

        $definition->validate();

        return $definition;
    }

    private function finalizeCurrentStep(): void
    {
        if ($this->currentStep === null) {
            return;
        }

        $step = $this->currentStep;

        /** @var class-string $forwardAction */
        $forwardAction = $step->forwardAction;

        $this->steps[$step->name] = new SagaStep(
            name: $step->name,
            forwardAction: $forwardAction,
            forwardIdempotencyKey: $step->forwardIdempotencyKey,
            compensationAction: $step->compensationAction,
            compensationIdempotencyKey: $step->compensationIdempotencyKey,
            retryPolicy: $step->retryPolicy,
            compensationRetryPolicy: $step->compensationRetryPolicy,
            irreversible: $step->irreversible,
        );
        $this->currentStep = null;
    }

    private function requireCurrentStep(): StepBuilder
    {
        if ($this->currentStep === null) {
            throw SagaException::noStepsDefined($this->name);
        }

        return $this->currentStep;
    }
}
