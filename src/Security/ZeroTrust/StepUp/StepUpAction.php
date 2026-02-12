<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\StepUp;

use Pulsar\Api\Api;

/**
 * Action to take after evaluating step-up authentication state.
 *
 * Redirect: the user should be sent to a step-up authentication flow.
 * Deny: the user is locked out and cannot attempt step-up.
 * Allow: step-up authentication was successful and access is granted.
 */
#[Api(since: '1.0.0')]
enum StepUpAction: string
{
    case Redirect = 'redirect';
    case Deny = 'deny';
    case Allow = 'allow';
}
