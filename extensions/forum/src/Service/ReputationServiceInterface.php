<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Service;

use Pulsar\Api\Api;
use Pulsar\Extension\Forum\Domain\ReputationLevel;
use Pulsar\Extension\Forum\Profile\ForumProfile;

/**
 * Reputation service: manage user reputation scores and level progression.
 * @api
 */
#[Api(since: '1.0.0')]
interface ReputationServiceInterface
{
    public function addReputation(string $userId, int $points, string $reason): ForumProfile;

    public function getLevel(string $userId): ReputationLevel;

    public function isEligibleForPromotion(string $userId): bool;

    public function getOrCreateProfile(string $userId, ?string $tenantId = null): ForumProfile;
}
