<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Definition;

use Pulsar\Api\Api;

/**
 * Determines how the workflow engine manages active states.
 *
 * StateMachine: exactly one active state at any time — transitions move from
 * one state to another in strict sequence.
 *
 * Workflow: multiple states may be active concurrently — transitions can fork
 * into parallel branches and join back.
 */
#[Api(since: '1.0.0')]
enum WorkflowType: string
{
    case StateMachine = 'state_machine';
    case Workflow = 'workflow';
}
