<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Listener;

use Pulsar\Api\Api;
use Pulsar\Workflow\ActorContext;
use Pulsar\Workflow\Definition\TransitionDefinition;
use Pulsar\Workflow\Storage\WorkflowInstance;

/**
 * Listener for workflow transition lifecycle events.
 *
 * Each hook is called at its respective phase of the transition process.
 * Implementations should be fast and side-effect-aware; heavy work should
 * be deferred to async handlers via the event dispatcher.
 */
#[Api(since: '1.0.0')]
interface TransitionListenerInterface
{
    /**
     * Called before leaving the current state.
     *
     * Executes after guards have passed but before the state change is persisted.
     */
    public function onLeave(
        WorkflowInstance $instance,
        TransitionDefinition $transition,
        ActorContext $actor,
    ): void;

    /**
     * Called during the transition (after leave, before enter).
     */
    public function onTransition(
        WorkflowInstance $instance,
        TransitionDefinition $transition,
        ActorContext $actor,
    ): void;

    /**
     * Called after entering the new state.
     *
     * Executes after the state change has been persisted successfully.
     */
    public function onEnter(
        WorkflowInstance $instance,
        TransitionDefinition $transition,
        ActorContext $actor,
    ): void;
}
