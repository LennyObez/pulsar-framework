<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Storage;

use Pulsar\Api\Api;

/**
 * Direction of a saga step execution.
 */
#[Api(since: '1.0.0')]
enum SagaStepDirection: string
{
    case Forward = 'forward';
    case Compensating = 'compensating';
}
