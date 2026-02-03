<?php

declare(strict_types=1);

namespace Pulsar\Auth\Identity;

/**
 * Represents the two-factor authentication status of an identity.
 */
enum TwoFactorStatus: string
{
    case Disabled = 'disabled';
    case Pending = 'pending';
    case Verified = 'verified';
}
