<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Social\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Auth\Social\Domain\LinkedIdentityResult;
use Pulsar\Extension\Auth\Social\Domain\SocialIdentity;

/**
 * Links a social identity to an application user account.
 *
 * Implementations handle account creation, merging, or linking
 * based on the application's user management strategy.
 * @api
 */
#[Api(since: '1.0.0')]
interface SocialIdentityLinkerInterface
{
    /**
     * Link a social identity to an application user.
     */
    public function link(SocialIdentity $socialIdentity): LinkedIdentityResult;
}
