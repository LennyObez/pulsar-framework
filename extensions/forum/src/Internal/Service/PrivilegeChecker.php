<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Internal\Service;

use Pulsar\Api\Internal;
use Pulsar\Extension\Forum\Domain\ForumPrivilege;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Service\PrivilegeCheckerInterface;

/**
 * Compares a user's reputation score against the privilege's threshold.
 */
#[Internal(reason: 'Privilege enforcement — use PrivilegeCheckerInterface for public API')]
final readonly class PrivilegeChecker implements PrivilegeCheckerInterface
{
    public function canPerform(ForumPrivilege $privilege, ForumProfile $profile): bool
    {
        return $profile->reputationScore >= $privilege->requiredScore();
    }
}
