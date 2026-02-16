<?php

declare(strict_types=1);

namespace Pulsar\Saga\Internal;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Saga\Event\IrreversibleSagaFailureEvent;
use Pulsar\Saga\Event\SagaCompensationCompletedEvent;
use Pulsar\Saga\Event\SagaCompensationStartedEvent;
use Pulsar\Saga\Event\SagaCompletedEvent;
use Pulsar\Saga\Event\SagaFailedEvent;
use Pulsar\Saga\Event\SagaStartedEvent;
use Pulsar\Saga\Event\SagaStepCompletedEvent;
use Pulsar\Saga\Event\SagaStepFailedEvent;
use Pulsar\Saga\Exception\CompensationFailedException;
use Pulsar\Saga\Exception\SagaException;
use Pulsar\Saga\Port\CommandBusPort;
use Pulsar\Saga\SagaDefinition;
use Pulsar\Saga\SagaOrchestratorInterface;
use Pulsar\Saga\SagaState;
use Pulsar\Saga\SagaStateStorageInterface;
use Pulsar\Saga\SagaStatus;
use Pulsar\Saga\SagaStepResultStorageInterface;
use Pulsar\Saga\Step\SagaStep;
use Pulsar\Saga\Step\SagaStepDirection;
use Pulsar\Saga\Step\SagaStepResult as SagaStepResultRecord;
use Pulsar\Saga\Step\SagaStepStatus;
use Pulsar\Saga\Step\StepResult;
use Random\Engine\Secure;
use Random\Randomizer;
use Throwable;

use function array_reverse;
use function bin2hex;
use function count;
use function sprintf;

/**
 * Internal saga orchestrator implementation.
 *
 * Executes saga steps sequentially, persists state after each step,
 * and handles compensation with proper handling of irreversible steps.
 */
#[Internal(reason: 'Use SagaOrchestratorInterface as the public API')]
final readonly class SagaOrchestrator implements SagaOrchestratorInterface
{
    private Randomizer $randomizer;

    public function __construct(
        private CommandBusPort $commandBus,
        private SagaStateStorageInterface $storage,
        private EventDispatcherInterface $eventDispatcher,
        private ?SagaStepResultStorageInterface $stepResultStorage = null,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
    }

    #[Override]
    public function execute(SagaDefinition $definition, array $context): SagaState
    {
        $definition->validate();

        $sagaId = $this->generateId();
        $state = SagaState::initial(
            sagaId: $sagaId,
            definitionId: $definition->name,
            definitionVersion: 1,
            context: $context,
        );

        $this->storage->save($state);

        $this->eventDispatcher->dispatch(new SagaStartedEvent(
            sagaId: $sagaId,
            definitionId: $definition->name,
            definitionVersion: 1,
            totalSteps: $definition->stepCount(),
            occurredAt: new DateTimeImmutable(),
        ));

        return $this->runForward($state, $definition);
    }

    #[Override]
    public function resume(string $sagaId, SagaDefinition $definition): SagaState
    {
        $state = $this->loadState($sagaId);

        if ($state->status === SagaStatus::Completed || $state->status === SagaStatus::Failed) {
            throw SagaException::invalidState($sagaId, 'running', $state->status->value);
        }

        if ($state->status === SagaStatus::Compensating) {
            return $this->runCompensation($state, $definition);
        }

        return $this->runForward($state, $definition);
    }

    #[Override]
    public function compensate(string $sagaId, SagaDefinition $definition): SagaState
    {
        $state = $this->loadState($sagaId);

        if ($state->status !== SagaStatus::Running) {
            throw SagaException::invalidState($sagaId, 'running', $state->status->value);
        }

        $state = $state->withCompensating();
        $this->storage->save($state);

        return $this->runCompensation($state, $definition);
    }

    /**
     * Execute forward steps from the current position.
     */
    private function runForward(SagaState $state, SagaDefinition $definition): SagaState
    {
        $steps = $definition->getStepNames();
        $totalSteps = count($steps);

        for ($i = $state->currentStepIndex; $i < $totalSteps; $i++) {
            $step = $definition->getStepAtIndex($i);
            $idempotencyKey = $step->forwardIdempotencyKey !== null
                ? ($step->forwardIdempotencyKey)($state->context)
                : null;
            $stepResultId = $this->generateId();

            $this->recordStepStarted(
                $stepResultId,
                $state->sagaId,
                $step->name,
                $i,
                SagaStepDirection::Forward,
                $idempotencyKey,
            );

            try {
                $result = $this->executeStepWithRetry($step, $state->context, false);
            } catch (Throwable $e) {
                $this->recordStepFailed($stepResultId, $e->getMessage());

                $this->eventDispatcher->dispatch(new SagaStepFailedEvent(
                    sagaId: $state->sagaId,
                    stepName: $step->name,
                    stepIndex: $i,
                    errorMessage: $e->getMessage(),
                    occurredAt: new DateTimeImmutable(),
                ));

                $state = $state->withCompensating();
                $this->storage->save($state);

                return $this->handleFailure($state, $definition, $step->name, $e);
            }

            $this->recordStepCompleted($stepResultId, $result->output !== [] ? $result->output : null);

            $state = $state->withStepCompleted($result);

            if ($result->output !== []) {
                $state = $state->withContext($result->output);
            }

            $this->storage->save($state);

            $this->eventDispatcher->dispatch(new SagaStepCompletedEvent(
                sagaId: $state->sagaId,
                stepName: $step->name,
                stepIndex: $i,
                output: $result->output,
                occurredAt: new DateTimeImmutable(),
            ));
        }

        $state = $state->withCompleted();
        $this->storage->save($state);

        $this->eventDispatcher->dispatch(new SagaCompletedEvent(
            sagaId: $state->sagaId,
            definitionId: $definition->name,
            totalSteps: $totalSteps,
            occurredAt: new DateTimeImmutable(),
        ));

        return $state;
    }

    /**
     * Handle a step failure by triggering compensation.
     */
    private function handleFailure(
        SagaState $state,
        SagaDefinition $definition,
        string $failedStepName,
        Throwable $error,
    ): SagaState {
        $state = $this->runCompensation($state, $definition);

        $this->eventDispatcher->dispatch(new SagaFailedEvent(
            sagaId: $state->sagaId,
            definitionId: $definition->name,
            failedStepName: $failedStepName,
            errorMessage: $error->getMessage(),
            compensationSuccessful: $state->status === SagaStatus::Failed,
            occurredAt: new DateTimeImmutable(),
        ));

        return $state;
    }

    /**
     * Run compensation for completed steps in reverse order.
     */
    private function runCompensation(SagaState $state, SagaDefinition $definition): SagaState
    {
        $completedResults = $state->stepResults;
        $stepsToCompensate = count($completedResults);

        $this->eventDispatcher->dispatch(new SagaCompensationStartedEvent(
            sagaId: $state->sagaId,
            failedStepName: $stepsToCompensate > 0
                ? $completedResults[$stepsToCompensate - 1]->stepName
                : '(none)',
            stepsToCompensate: $stepsToCompensate,
            occurredAt: new DateTimeImmutable(),
        ));

        $irreversibleSteps = [];
        $compensated = 0;
        $compensationIndex = 0;

        foreach (array_reverse($completedResults) as $result) {
            $step = $definition->getStep($result->stepName);

            if ($step->irreversible) {
                $irreversibleSteps[] = $step->name;

                $this->recordStepSkipped(
                    $state->sagaId,
                    $step->name,
                    $compensationIndex,
                );

                $compensationIndex++;

                continue;
            }

            if (!$step->hasCompensation()) {
                $compensationIndex++;

                continue;
            }

            $compensationIdempotencyKey = $step->compensationIdempotencyKey !== null
                ? ($step->compensationIdempotencyKey)($state->context)
                : null;
            $stepResultId = $this->generateId();

            $this->recordStepStarted(
                $stepResultId,
                $state->sagaId,
                $step->name,
                $compensationIndex,
                SagaStepDirection::Compensating,
                $compensationIdempotencyKey,
            );

            try {
                $this->executeStepWithRetry($step, $state->context, true);
                $this->recordStepCompleted($stepResultId, null);
                $compensated++;
            } catch (Throwable $e) {
                $this->recordStepFailed($stepResultId, $e->getMessage());
                $this->emitIrreversibleFailureIfNeeded($state, $definition, $irreversibleSteps, $result->stepName, $e);

                $state = $state->withFailed();
                $this->storage->save($state);

                throw CompensationFailedException::forStep($state->sagaId, $step->name, $e);
            }

            $compensationIndex++;
        }

        $this->emitIrreversibleFailureIfNeeded(
            $state,
            $definition,
            $irreversibleSteps,
            $stepsToCompensate > 0 ? $completedResults[$stepsToCompensate - 1]->stepName : '(unknown)',
            null,
        );

        $this->eventDispatcher->dispatch(new SagaCompensationCompletedEvent(
            sagaId: $state->sagaId,
            stepsCompensated: $compensated,
            occurredAt: new DateTimeImmutable(),
        ));

        $state = $state->withFailed();
        $this->storage->save($state);

        return $state;
    }

    /**
     * Execute a step's forward or compensation action with retry policy.
     *
     * @param array<string, mixed> $context
     */
    private function executeStepWithRetry(SagaStep $step, array $context, bool $compensation): StepResult
    {
        $actionClass = $compensation ? $step->compensationAction : $step->forwardAction;
        $policy = $compensation ? $step->compensationRetryPolicy : $step->retryPolicy;
        $keyGenerator = $compensation ? $step->compensationIdempotencyKey : $step->forwardIdempotencyKey;

        if ($actionClass === null) {
            return StepResult::success($step->name);
        }

        $idempotencyKey = $keyGenerator !== null ? ($keyGenerator)($context) : null;
        $lastError = null;

        for ($attempt = 1; $attempt <= $policy->maxAttempts; $attempt++) {
            try {
                $output = $this->commandBus->dispatch($actionClass, $context, $idempotencyKey);

                return StepResult::success($step->name, $output);
            } catch (Throwable $e) {
                $lastError = $e;

                if ($attempt < $policy->maxAttempts) {
                    $delayMs = $policy->delayForAttempt($attempt + 1);

                    if ($delayMs > 0) {
                        usleep($delayMs * 1000);
                    }
                }
            }
        }

        /** @var Throwable $lastError */
        throw $lastError;
    }

    /**
     * Emit IrreversibleSagaFailureEvent if there are irreversible steps that completed.
     *
     * @param list<string> $irreversibleSteps
     */
    private function emitIrreversibleFailureIfNeeded(
        SagaState $state,
        SagaDefinition $definition,
        array $irreversibleSteps,
        string $failedStepName,
        ?Throwable $error,
    ): void {
        if ($irreversibleSteps === []) {
            return;
        }

        $this->eventDispatcher->dispatch(new IrreversibleSagaFailureEvent(
            sagaId: $state->sagaId,
            definitionId: $definition->name,
            completedIrreversibleSteps: $irreversibleSteps,
            failedStepName: $failedStepName,
            errorMessage: $error !== null ? $error->getMessage() : 'Saga failed after irreversible steps',
            operatorActionHint: sprintf(
                'Review irreversible steps [%s] in saga "%s" and take manual corrective action',
                implode(', ', $irreversibleSteps),
                $state->sagaId,
            ),
            occurredAt: new DateTimeImmutable(),
        ));
    }

    private function loadState(string $sagaId): SagaState
    {
        $state = $this->storage->findById($sagaId);

        if ($state === null) {
            throw SagaException::sagaNotFound($sagaId);
        }

        return $state;
    }

    /**
     * Record a step execution as started (pending -> running).
     */
    private function recordStepStarted(
        string $stepResultId,
        string $instanceId,
        string $stepName,
        int $stepIndex,
        SagaStepDirection $direction,
        ?string $idempotencyKey,
    ): void {
        $this->stepResultStorage?->record(new SagaStepResultRecord(
            id: $stepResultId,
            instanceId: $instanceId,
            stepName: $stepName,
            stepIndex: $stepIndex,
            direction: $direction,
            status: SagaStepStatus::Running,
            idempotencyKey: $idempotencyKey,
            attempts: 1,
            resultData: null,
            errorMessage: null,
            startedAt: new DateTimeImmutable(),
            completedAt: null,
        ));
    }

    /**
     * Mark a step result as completed with optional output data.
     *
     * @param array<string, mixed>|null $resultData
     */
    private function recordStepCompleted(string $stepResultId, ?array $resultData): void
    {
        $this->stepResultStorage?->markCompleted($stepResultId, $resultData);
    }

    /**
     * Mark a step result as failed with an error message.
     */
    private function recordStepFailed(string $stepResultId, string $errorMessage): void
    {
        $this->stepResultStorage?->updateStatus($stepResultId, SagaStepStatus::Failed, $errorMessage);
    }

    /**
     * Record an irreversible step as skipped during compensation.
     */
    private function recordStepSkipped(
        string $instanceId,
        string $stepName,
        int $compensationIndex,
    ): void {
        $this->stepResultStorage?->record(new SagaStepResultRecord(
            id: $this->generateId(),
            instanceId: $instanceId,
            stepName: $stepName,
            stepIndex: $compensationIndex,
            direction: SagaStepDirection::Compensating,
            status: SagaStepStatus::Skipped,
            idempotencyKey: null,
            attempts: 0,
            resultData: null,
            errorMessage: null,
            startedAt: new DateTimeImmutable(),
            completedAt: new DateTimeImmutable(),
        ));
    }

    private function generateId(): string
    {
        return bin2hex($this->randomizer->getBytes(16));
    }
}
