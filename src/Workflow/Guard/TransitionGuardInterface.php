<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Guard;

use Pulsar\Api\Api;
use Pulsar\Workflow\ActorContext;
use Pulsar\Workflow\Definition\TransitionDefinition;
use Pulsar\Workflow\Storage\WorkflowInstance;

/**
 * Contract for guards that evaluate whether a transition is allowed.
 *
 * Guards receive the actor context and transition details, and must return
 * a deterministic result based solely on these inputs. Guards MUST NOT
 * perform hidden I/O — any required data must be available through the
 * actor context or workflow instance.
 *
 * Guard evaluation results are logged for audit purposes.
 */
#[Api(since: '1.0.0')]
interface TransitionGuardInterface
{
    /**
     * Evaluate whether the transition should be allowed.
     *
     * Implementations must be deterministic: same inputs always produce
     * the same result. No hidden I/O is allowed unless via explicit ports.
     */
    public function evaluate(
        ActorContext $actor,
        TransitionDefinition $transition,
        WorkflowInstance $instance,
    ): GuardResult;
}
