<?php

declare(strict_types=1);

namespace Pulsar\Security\ThreatDetection;

use Pulsar\Api\Api;

/**
 * Action to take in response to a detected threat.
 */
#[Api(since: '1.0.0')]
enum ThreatResponse: string
{
    /** Block the request entirely (return 403). */
    case Block = 'block';

    /** Apply escalated rate limiting to the source. */
    case RateLimit = 'rate_limit';

    /** Present a challenge (CAPTCHA, MFA step-up). */
    case Challenge = 'challenge';

    /** Alert operators but allow the request. */
    case Alert = 'alert';

    /** Log the event for forensic analysis only. */
    case Log = 'log';
}
