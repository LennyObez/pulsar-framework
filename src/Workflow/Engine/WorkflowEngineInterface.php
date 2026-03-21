<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Engine;

use Pulsar\Api\Api;
use Pulsar\Workflow\ActorContext;
use Pulsar\Workflow\Definition\TransitionDefinition;
use Pulsar\Workflow\Definition\WorkflowDefinition;
use Pulsar\Workflow\Exception\ConcurrentTransitionException;
use Pulsar\Workflow\Exception\WorkflowException;
use Pulsar\Workflow\Storage\ClassifiedContext;
use Pulsar\Workflow\Storage\WorkflowInstance;

/**
 * Public API for the workflow engine.
 *
 * Coordinates definition lookup, guard evaluation, state transitions,
 * event dispatching, and audit logging.
 * @api
 */
#[Api(since: '1.0.0')]
interface WorkflowEngineInterface
{
    /**
     * Start a new workflow instance from a definition.
     *
     * Creates a new instance in the initial state, persists it, dispatches
     * a WorkflowStartedEvent, and returns the new instance.
     */
    public function start(
        WorkflowDefinition $definition,
        ActorContext $actor,
        ClassifiedContext $context,
    ): WorkflowInstance;

    /**
     * Apply a named transition to a workflow instance.
     *
     * Evaluates all guards, executes transition listeners, persists the state
     * change with optimistic locking, records the transition in the audit log,
     * and dispatches domain events.
     *
     * If the new state is a final state, the instance is marked as completed
     * and a WorkflowCompletedEvent is dispatched.
     *
     * @throws WorkflowException If the transition is not valid from the current state
     * @throws WorkflowException If any guard blocks the transition
     * @throws ConcurrentTransitionException If the instance was modified concurrently
     */
    public function apply(
        WorkflowInstance $instance,
        WorkflowDefinition $definition,
        string $transitionName,
        ActorContext $actor,
        ?string $reason = null,
    ): WorkflowInstance;

    /**
     * Check if a transition can be applied to an instance.
     *
     * Evaluates the transition validity and all guards without making changes.
     * Returns true only if the transition is defined and all guards allow it.
     */
    public function can(
        WorkflowInstance $instance,
        WorkflowDefinition $definition,
        string $transitionName,
        ActorContext $actor,
    ): bool;

    /**
     * Get all transitions that are currently available from the instance's state.
     *
     * Evaluates guards for each potential transition and returns only those
     * that are both defined and allowed by all guards.
     *
     * @return list<TransitionDefinition>
     */
    public function getEnabledTransitions(
        WorkflowInstance $instance,
        WorkflowDefinition $definition,
        ActorContext $actor,
    ): array;
}
