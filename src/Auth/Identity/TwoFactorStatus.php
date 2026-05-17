<?php

declare(strict_types=1);

namespace Pulsar\Auth\Identity;

use Pulsar\Api\Api;

/**
 * Represents the two-factor authentication status of an identity.
 * @api
 */
#[Api(since: '1.0.0')]
enum TwoFactorStatus: string
{
    case Disabled = 'disabled';
    case Pending = 'pending';
    case Verified = 'verified';
}
