<?php

declare(strict_types=1);

namespace Pulsar\Saga;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Saga\Step\StepResult;

/**
 * Durable state of a saga execution.
 *
 * Persisted after each step to survive process restarts. The state tracks
 * which step the saga is on, the results of completed steps, and the
 * overall execution context.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SagaState
{
    /**
     * @param array<string, mixed>  $context        Saga execution context
     * @param list<StepResult>      $stepResults    Results of completed steps
     * @param int<0, max>           $currentStepIndex Index of the next step to execute
     */
    public function __construct(
        public string $sagaId,
        public string $definitionId,
        public int $definitionVersion,
        public int $currentStepIndex,
        public array $stepResults,
        public SagaStatus $status,
        public array $context,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $completedAt,
    ) {}

    /**
     * Create initial state for a new saga execution.
     *
     * @param array<string, mixed> $context
     */
    public static function initial(
        string $sagaId,
        string $definitionId,
        int $definitionVersion,
        array $context,
    ): self {
        return new self(
            sagaId: $sagaId,
            definitionId: $definitionId,
            definitionVersion: $definitionVersion,
            currentStepIndex: 0,
            stepResults: [],
            status: SagaStatus::Running,
            context: $context,
            startedAt: new DateTimeImmutable(),
            completedAt: null,
        );
    }

    /**
     * Advance to the next step with the given result.
     */
    #[NoDiscard]
    public function withStepCompleted(StepResult $result): self
    {
        return clone($this, [
            'currentStepIndex' => $this->currentStepIndex + 1,
            'stepResults' => [...$this->stepResults, $result],
        ]);
    }

    /**
     * Update the saga context with new values.
     *
     * @param array<string, mixed> $additionalContext
     */
    #[NoDiscard]
    public function withContext(array $additionalContext): self
    {
        return clone($this, [
            'context' => [...$this->context, ...$additionalContext],
        ]);
    }

    /**
     * Transition to compensating status.
     */
    #[NoDiscard]
    public function withCompensating(): self
    {
        return clone($this, [
            'status' => SagaStatus::Compensating,
        ]);
    }

    /**
     * Mark as completed.
     */
    #[NoDiscard]
    public function withCompleted(): self
    {
        return clone($this, [
            'status' => SagaStatus::Completed,
            'completedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Mark as failed.
     */
    #[NoDiscard]
    public function withFailed(): self
    {
        return clone($this, [
            'status' => SagaStatus::Failed,
            'completedAt' => new DateTimeImmutable(),
        ]);
    }
}
