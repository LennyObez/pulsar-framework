<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Storage;

use Pulsar\Api\Api;

/**
 * Status of a workflow instance in its lifecycle.
 * @api
 */
#[Api(since: '1.0.0')]
enum WorkflowInstanceStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Failed = 'failed';
    case Compensating = 'compensating';
}
