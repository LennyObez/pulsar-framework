<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E\Extension\Forum;

use Override;
use Pulsar\Extension\Forum\Domain\ReputationLevel;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Service\ReputationServiceInterface;

/**
 * @internal Stub reputation service for E2E tests.
 */
final class E2EReputationService implements ReputationServiceInterface
{
    public function __construct(
        private readonly E2EForumProfileRepository $profiles,
    ) {}

    #[Override]
    public function addReputation(string $userId, int $points, string $reason): ForumProfile
    {
        $profile = $this->getOrCreateProfile($userId);
        $updated = $profile->addReputation($points);
        $this->profiles->save($updated);

        return $updated;
    }

    #[Override]
    public function getLevel(string $userId): ReputationLevel
    {
        $profile = $this->profiles->findByUser($userId);

        return $profile !== null
            ? $profile->reputationLevel()
            : ReputationLevel::Newcomer;
    }

    #[Override]
    public function isEligibleForPromotion(string $userId): bool
    {
        return false;
    }

    #[Override]
    public function getOrCreateProfile(string $userId, ?string $tenantId = null): ForumProfile
    {
        $profile = $this->profiles->findByUser($userId, $tenantId);

        if ($profile === null) {
            $profile = ForumProfile::create(
                id: 'profile-' . $userId,
                userId: $userId,
                tenantId: $tenantId,
            );
            $this->profiles->save($profile);
        }

        return $profile;
    }
}
