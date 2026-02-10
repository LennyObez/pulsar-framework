<?php

declare(strict_types=1);

namespace Pulsar\Saga;

use Pulsar\Api\Api;

/**
 * Lifecycle status of a saga execution.
 */
#[Api(since: '1.0.0')]
enum SagaStatus: string
{
    case Running = 'running';
    case Compensating = 'compensating';
    case Completed = 'completed';
    case Failed = 'failed';
}
