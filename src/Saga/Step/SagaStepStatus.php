<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Storage;

use Pulsar\Api\Api;

/**
 * Execution status of an individual saga step.
 */
#[Api(since: '1.0.0')]
enum SagaStepStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
