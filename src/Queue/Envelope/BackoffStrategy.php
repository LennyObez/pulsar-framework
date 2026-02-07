<?php

declare(strict_types=1);

namespace Pulsar\Queue\Envelope;

use Pulsar\Api\Api;

/**
 * Strategy used to calculate delays between retry attempts.
 */
#[Api(since: '1.0.0')]
enum BackoffStrategy: string
{
    case Fixed = 'fixed';
    case Exponential = 'exponential';
    case Custom = 'custom';
}
