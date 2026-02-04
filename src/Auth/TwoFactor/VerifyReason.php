<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use Pulsar\Api\Api;

/**
 * Reason codes for TOTP verification outcomes.
 */
#[Api(since: '1.0.0')]
enum VerifyReason: string
{
    case Valid = 'valid';
    case InvalidCode = 'invalid_code';
    case Replayed = 'replayed';
    case Expired = 'expired';
    case NotEnrolled = 'not_enrolled';
    case RateLimited = 'rate_limited';
}
