<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\Policy;

use Pulsar\Api\Api;

/**
 * Outcome of a policy evaluation.
 *
 * Grant: all required claims are satisfied, access is allowed.
 * Deny: one or more required claims are missing or insufficient, access is refused.
 * StepUp: claims are partially satisfied; additional authentication is required.
 */
#[Api(since: '1.0.0')]
enum PolicyDecision: string
{
    case Grant = 'grant';
    case Deny = 'deny';
    case StepUp = 'step_up';
}
