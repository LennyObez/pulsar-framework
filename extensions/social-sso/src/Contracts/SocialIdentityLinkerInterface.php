<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\SocialSso\Domain\LinkedIdentityResult;
use Pulsar\Extension\SocialSso\Domain\SocialIdentity;

/**
 * Links a social identity to an application user account.
 *
 * Implementations handle account creation, merging, or linking
 * based on the application's user management strategy.
 */
#[Api(since: '1.0.0')]
interface SocialIdentityLinkerInterface
{
    /**
     * Link a social identity to an application user.
     */
    public function link(SocialIdentity $socialIdentity): LinkedIdentityResult;
}
