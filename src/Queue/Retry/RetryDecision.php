<?php

declare(strict_types=1);

namespace Pulsar\Queue\Retry;

use Pulsar\Api\Api;

/**
 * Outcome of a retry policy evaluation.
 */
#[Api]
enum RetryDecision: string
{
    case Retry = 'retry';
    case DeadLetter = 'dead_letter';
    case Discard = 'discard';
}
