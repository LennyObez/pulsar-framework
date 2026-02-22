<?php

declare(strict_types=1);

namespace Pulsar\Saga\Step;

use Pulsar\Api\Api;

/**
 * Backoff strategy for retry policies.
 */
#[Api(since: '1.0.0')]
enum BackoffStrategy: string
{
    case Linear = 'linear';
    case Exponential = 'exponential';
}
