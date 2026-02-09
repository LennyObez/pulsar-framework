<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use Pulsar\Api\Api;

/**
 * Reason codes for recovery code consume operations.
 */
#[Api(since: '1.0.0')]
enum ConsumeReason: string
{
    case Consumed = 'consumed';
    case NotFound = 'not_found';
    case AlreadyUsed = 'already_used';
    case NotEnrolled = 'not_enrolled';
    case RateLimited = 'rate_limited';
}
