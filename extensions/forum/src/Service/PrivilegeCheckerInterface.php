<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Service;

use Pulsar\Api\Api;
use Pulsar\Extension\Forum\Domain\ForumPrivilege;
use Pulsar\Extension\Forum\Profile\ForumProfile;

/**
 * Determines whether a user's reputation grants a specific forum privilege.
 */
#[Api(since: '1.0.0')]
interface PrivilegeCheckerInterface
{
    /**
     * Check if the user's profile meets the reputation requirement for a privilege.
     */
    public function canPerform(ForumPrivilege $privilege, ForumProfile $profile): bool;
}
