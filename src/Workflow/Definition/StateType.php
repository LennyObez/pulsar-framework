<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Definition;

use Pulsar\Api\Api;

/**
 * Classification of a state within a workflow definition.
 *
 * Initial: the starting point: exactly one required per definition.
 * Intermediate: a transient state between initial and final.
 * Final: a terminal state: no outgoing transitions allowed.
 */
#[Api(since: '1.0.0')]
enum StateType: string
{
    case Initial = 'initial';
    case Intermediate = 'intermediate';
    case Final = 'final';
}
