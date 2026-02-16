<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Internal\Service;

use Pulsar\Api\Internal;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Forum\Domain\ReputationLevel;
use Pulsar\Extension\Forum\Event\ReputationChanged;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Service\ReputationServiceInterface;
use Pulsar\Extension\Forum\Support\UuidGenerator;

use function count;

/**
 * Reputation service: manages reputation scores and level thresholds.
 */
#[Internal(reason: 'Use ReputationServiceInterface for public API')]
final readonly class ReputationService implements ReputationServiceInterface
{
    public function __construct(
        private ForumProfileRepositoryInterface $profiles,
        private EventDispatcherInterface $events,
    ) {}

    public function addReputation(string $userId, int $points, string $reason): ForumProfile
    {
        $profile = $this->profiles->findByUser($userId);

        if ($profile === null) {
            throw ForumException::notFound('ForumProfile', $userId);
        }

        $previousScore = $profile->reputationScore;

        // C-2: Atomic SQL increment instead of read-modify-write
        $this->profiles->incrementReputation($userId, $points, $profile->tenantId);

        // Re-fetch profile to get the updated score for the event
        $profile = $this->profiles->findByUser($userId);

        if ($profile === null) {
            throw ForumException::notFound('ForumProfile', $userId);
        }

        $this->events->dispatch(new ReputationChanged(
            userId: $profile->userId,
            previousScore: $previousScore,
            newScore: $profile->reputationScore,
            delta: $points,
            reason: $reason,
            tenantId: $profile->tenantId,
        ));

        return $profile;
    }

    public function getLevel(string $userId): ReputationLevel
    {
        $profile = $this->profiles->findByUser($userId);

        if ($profile === null) {
            throw ForumException::notFound('ForumProfile', $userId);
        }

        return $profile->reputationLevel();
    }

    public function isEligibleForPromotion(string $userId): bool
    {
        $profile = $this->profiles->findByUser($userId);

        if ($profile === null) {
            return false;
        }

        $currentLevel = $profile->reputationLevel();

        // Check if the next level threshold is within reach
        $levels = ReputationLevel::cases();
        $currentIndex = -1;

        foreach ($levels as $index => $level) {
            if ($level === $currentLevel) {
                $currentIndex = $index;

                break;
            }
        }

        // Already at the highest level
        if ($currentIndex >= count($levels) - 1) {
            return false;
        }

        $nextLevel = $levels[$currentIndex + 1];

        return $profile->reputationScore >= $nextLevel->minimumScore();
    }

    public function getOrCreateProfile(string $userId, ?string $tenantId = null): ForumProfile
    {
        $profile = $this->profiles->findByUser($userId, $tenantId);

        if ($profile !== null) {
            return $profile;
        }

        $profile = ForumProfile::create(
            id: UuidGenerator::v7(),
            userId: $userId,
            tenantId: $tenantId,
        );

        $this->profiles->save($profile);

        return $profile;
    }
}
