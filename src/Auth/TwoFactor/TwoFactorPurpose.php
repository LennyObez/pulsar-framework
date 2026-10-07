<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use Pulsar\Api\Api;

/**
 * Purpose of a two-factor authentication verification.
 * @api
 */
#[Api(since: '1.0.0')]
enum TwoFactorPurpose: string
{
    case Login = 'login';
    case Setup = 'setup';
    case StepUp = 'step_up';
}
