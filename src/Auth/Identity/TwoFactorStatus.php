<?php

declare(strict_types=1);

namespace Pulsar\Auth\Identity;

use Pulsar\Api\Api;

/**
 * Represents the two-factor authentication status of an identity.
 */
#[Api]
enum TwoFactorStatus: string
{
    case Disabled = 'disabled';
    case Pending = 'pending';
    case Verified = 'verified';
}
