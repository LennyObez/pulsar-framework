<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Internal\Engine;

use DateInterval;
use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Workflow\ActorContext;
use Pulsar\Workflow\Definition\StateDefinition;
use Pulsar\Workflow\Definition\TransitionDefinition;
use Pulsar\Workflow\Definition\WorkflowDefinition;
use Pulsar\Workflow\Engine\WorkflowEngineInterface;
use Pulsar\Workflow\Event\TransitionAppliedEvent;
use Pulsar\Workflow\Event\TransitionBlockedEvent;
use Pulsar\Workflow\Event\WorkflowCompletedEvent;
use Pulsar\Workflow\Event\WorkflowStartedEvent;
use Pulsar\Workflow\Exception\WorkflowException;
use Pulsar\Workflow\Guard\GuardResolverInterface;
use Pulsar\Workflow\Listener\TransitionListenerInterface;
use Pulsar\Workflow\Storage\ClassifiedContext;
use Pulsar\Workflow\Storage\TransitionLogInterface;
use Pulsar\Workflow\Storage\TransitionRecord;
use Pulsar\Workflow\Storage\WorkflowInstance;
use Pulsar\Workflow\Storage\WorkflowInstanceStatus;
use Pulsar\Workflow\Storage\WorkflowStorageInterface;
use Pulsar\Workflow\Timeout\TimeoutHandlerInterface;
use Random\Engine\Secure;
use Random\Randomizer;

use function array_filter;
use function array_values;
use function bin2hex;
use function is_string;

/**
 * Internal workflow engine implementation.
 *
 * Coordinates guard evaluation, transition listeners, state persistence with
 * optimistic locking, audit logging, and domain event dispatching.
 */
#[Internal(reason: 'Use WorkflowEngineInterface as the public API')]
final readonly class WorkflowEngine implements WorkflowEngineInterface
{
    /** @var list<TransitionListenerInterface> */
    private array $listeners;

    private Randomizer $randomizer;

    /**
     * @param list<TransitionListenerInterface> $listeners
     */
    public function __construct(
        private WorkflowStorageInterface $storage,
        private TransitionLogInterface $transitionLog,
        private GuardResolverInterface $guardResolver,
        private EventDispatcherInterface $eventDispatcher,
        private AuditLoggerInterface $auditLogger,
        array $listeners = [],
        private ?TimeoutHandlerInterface $timeoutHandler = null,
        ?Randomizer $randomizer = null,
    ) {
        $this->listeners = $listeners;
        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
    }

    #[Override]
    public function start(
        WorkflowDefinition $definition,
        ActorContext $actor,
        ClassifiedContext $context,
    ): WorkflowInstance {
        $initialState = $definition->getInitialState();
        $now = new DateTimeImmutable();
        $instanceId = $this->generateId();

        $instance = new WorkflowInstance(
            id: $instanceId,
            definitionId: $definition->name,
            definitionVersion: 1,
            currentState: $initialState->name,
            context: $context,
            version: 1,
            status: WorkflowInstanceStatus::Active,
            startedAt: $now,
            completedAt: null,
            startedBy: $actor->subjectId,
        );

        $this->storage->create($instance);

        $this->auditLogger->log(
            event: AuditEvent::DataModification,
            outcome: AuditOutcome::Success,
            actor: $actor->subjectId,
            action: 'workflow.start',
            resource: $definition->name,
            metadata: [
                'instance_id' => $instanceId,
                'initial_state' => $initialState->name,
            ],
        );

        $this->eventDispatcher->dispatch(new WorkflowStartedEvent(
            instanceId: $instanceId,
            definitionId: $definition->name,
            definitionVersion: 1,
            initialState: $initialState->name,
            actor: $actor,
            occurredAt: $now,
        ));

        $this->scheduleTimeoutIfDefined($instanceId, $initialState);

        return $instance;
    }

    #[Override]
    public function apply(
        WorkflowInstance $instance,
        WorkflowDefinition $definition,
        string $transitionName,
        ActorContext $actor,
        ?string $reason = null,
    ): WorkflowInstance {
        $currentState = $definition->getState($instance->currentState);

        if ($currentState->isFinal()) {
            throw WorkflowException::transitionFromFinalState($instance->currentState, $definition->name);
        }

        $transition = $this->resolveTransition($definition, $transitionName, $instance->currentState);

        $guardDenials = $this->evaluateGuards($transition, $actor, $instance);

        if ($guardDenials !== []) {
            $this->dispatchBlockedEvent($instance, $definition, $transition, $actor, $guardDenials);

            throw WorkflowException::guardBlocked(
                $transitionName,
                'guards',
                $guardDenials[0],
            );
        }

        $this->notifyListenersLeave($instance, $transition, $actor);
        $this->notifyListenersTransition($instance, $transition, $actor);

        $now = new DateTimeImmutable();

        $updatedInstance = $this->storage->updateState(
            id: $instance->id,
            newState: $transition->to,
            expectedVersion: $instance->version,
            actor: $actor,
            reason: $reason,
        );

        $this->transitionLog->record(new TransitionRecord(
            id: $this->generateId(),
            instanceId: $instance->id,
            fromState: $instance->currentState,
            toState: $transition->to,
            transitionName: $transitionName,
            actor: $actor->subjectId,
            reason: $reason,
            metadata: $transition->metadata,
            instanceVersion: $updatedInstance->version,
            createdAt: $now,
        ));

        $this->auditLogger->log(
            event: AuditEvent::DataModification,
            outcome: AuditOutcome::Success,
            actor: $actor->subjectId,
            action: 'workflow.transition',
            resource: $definition->name,
            metadata: [
                'instance_id' => $instance->id,
                'transition' => $transitionName,
                'from_state' => $instance->currentState,
                'to_state' => $transition->to,
                'reason' => $reason,
            ],
        );

        $this->eventDispatcher->dispatch(new TransitionAppliedEvent(
            instanceId: $instance->id,
            definitionId: $definition->name,
            transitionName: $transitionName,
            fromState: $instance->currentState,
            toState: $transition->to,
            actor: $actor,
            reason: $reason,
            instanceVersion: $updatedInstance->version,
            occurredAt: $now,
        ));

        $this->notifyListenersEnter($updatedInstance, $transition, $actor);

        $this->cancelTimeoutIfActive($instance->id);

        $targetState = $definition->getState($transition->to);

        if ($targetState->isFinal()) {
            $this->storage->updateStatus($updatedInstance->id, WorkflowInstanceStatus::Completed);

            $this->eventDispatcher->dispatch(new WorkflowCompletedEvent(
                instanceId: $updatedInstance->id,
                definitionId: $definition->name,
                finalState: $transition->to,
                actor: $actor,
                occurredAt: $now,
            ));
        } else {
            $this->scheduleTimeoutIfDefined($updatedInstance->id, $targetState);
        }

        return $updatedInstance;
    }

    #[Override]
    public function can(
        WorkflowInstance $instance,
        WorkflowDefinition $definition,
        string $transitionName,
        ActorContext $actor,
    ): bool {
        if (!$definition->hasTransition($transitionName)) {
            return false;
        }

        $transition = $definition->getTransition($transitionName);

        if (!$transition->canTransitionFrom($instance->currentState)) {
            return false;
        }

        $currentState = $definition->getState($instance->currentState);

        if ($currentState->isFinal()) {
            return false;
        }

        $guardDenials = $this->evaluateGuards($transition, $actor, $instance);

        return $guardDenials === [];
    }

    #[Override]
    public function getEnabledTransitions(
        WorkflowInstance $instance,
        WorkflowDefinition $definition,
        ActorContext $actor,
    ): array {
        $candidates = $definition->getTransitionsFrom($instance->currentState);

        return array_values(array_filter(
            $candidates,
            fn(TransitionDefinition $t): bool => $this->evaluateGuards($t, $actor, $instance) === [],
        ));
    }

    /**
     * Resolve and validate a transition from the definition.
     */
    private function resolveTransition(
        WorkflowDefinition $definition,
        string $transitionName,
        string $currentState,
    ): TransitionDefinition {
        if (!$definition->hasTransition($transitionName)) {
            throw WorkflowException::invalidTransition($transitionName, $currentState, $definition->name);
        }

        $transition = $definition->getTransition($transitionName);

        if (!$transition->canTransitionFrom($currentState)) {
            throw WorkflowException::invalidTransition($transitionName, $currentState, $definition->name);
        }

        return $transition;
    }

    /**
     * Evaluate all guards for a transition.
     *
     * @return list<string> Denial reasons (empty if all guards pass)
     */
    private function evaluateGuards(
        TransitionDefinition $transition,
        ActorContext $actor,
        WorkflowInstance $instance,
    ): array {
        $denials = [];

        foreach ($transition->guards as $guardClass) {
            $guard = $this->guardResolver->resolve($guardClass);
            $result = $guard->evaluate($actor, $transition, $instance);

            $this->auditLogger->log(
                event: AuditEvent::Authorization,
                outcome: $result->isAllowed() ? AuditOutcome::Success : AuditOutcome::Failure,
                actor: $actor->subjectId,
                action: 'workflow.guard.evaluate',
                resource: $guardClass,
                metadata: [
                    'transition' => $transition->name,
                    'allowed' => $result->isAllowed(),
                    'reason' => $result->reason,
                ],
            );

            if ($result->isDenied()) {
                $denials[] = $result->reason;
            }
        }

        return $denials;
    }

    /**
     * @param list<string> $guardReasons
     */
    private function dispatchBlockedEvent(
        WorkflowInstance $instance,
        WorkflowDefinition $definition,
        TransitionDefinition $transition,
        ActorContext $actor,
        array $guardReasons,
    ): void {
        $this->eventDispatcher->dispatch(new TransitionBlockedEvent(
            instanceId: $instance->id,
            definitionId: $definition->name,
            transitionName: $transition->name,
            fromState: $instance->currentState,
            actor: $actor,
            guardReasons: $guardReasons,
            occurredAt: new DateTimeImmutable(),
        ));
    }

    private function notifyListenersLeave(
        WorkflowInstance $instance,
        TransitionDefinition $transition,
        ActorContext $actor,
    ): void {
        foreach ($this->listeners as $listener) {
            $listener->onLeave($instance, $transition, $actor);
        }
    }

    private function notifyListenersTransition(
        WorkflowInstance $instance,
        TransitionDefinition $transition,
        ActorContext $actor,
    ): void {
        foreach ($this->listeners as $listener) {
            $listener->onTransition($instance, $transition, $actor);
        }
    }

    private function notifyListenersEnter(
        WorkflowInstance $instance,
        TransitionDefinition $transition,
        ActorContext $actor,
    ): void {
        foreach ($this->listeners as $listener) {
            $listener->onEnter($instance, $transition, $actor);
        }
    }

    /**
     * Schedule a timeout if the state definition has a 'timeout' metadata key.
     *
     * The timeout value must be an ISO 8601 duration string (e.g., 'PT1H', 'P1D').
     */
    private function scheduleTimeoutIfDefined(string $instanceId, StateDefinition $state): void
    {
        if ($this->timeoutHandler === null) {
            return;
        }

        $timeout = $state->metadata['timeout'] ?? null;

        if (!is_string($timeout) || $timeout === '') {
            return;
        }

        $this->timeoutHandler->scheduleTimeout($instanceId, new DateInterval($timeout));
    }

    /**
     * Cancel any active timeout for the given instance.
     */
    private function cancelTimeoutIfActive(string $instanceId): void
    {
        $this->timeoutHandler?->cancelTimeout($instanceId);
    }

    private function generateId(): string
    {
        return bin2hex($this->randomizer->getBytes(16));
    }
}
