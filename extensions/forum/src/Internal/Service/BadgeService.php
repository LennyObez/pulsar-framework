<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Internal\Service;

use Pulsar\Api\Internal;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Forum\Badge\BadgeServiceInterface;
use Pulsar\Extension\Forum\Badge\UserBadge;
use Pulsar\Extension\Forum\Badge\UserBadgeRepositoryInterface;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Domain\Badge;
use Pulsar\Extension\Forum\Domain\ReputationLevel;
use Pulsar\Extension\Forum\Event\BadgeAwarded;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Support\UuidGenerator;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;

/**
 * Badge service — evaluates badge criteria, awards and revokes badges.
 */
#[Internal(reason: 'Use BadgeServiceInterface for public API')]
final readonly class BadgeService implements BadgeServiceInterface
{
    public function __construct(
        private UserBadgeRepositoryInterface $userBadges,
        private ForumProfileRepositoryInterface $profiles,
        private PostRepositoryInterface $posts,
        private ThreadRepositoryInterface $threads,
        private EventDispatcherInterface $events,
        private ForumConfig $config,
    ) {}

    public function evaluate(string $userId, Badge $badge): bool
    {
        if (!$this->config->badges->enabled) {
            return false;
        }

        $profile = $this->profiles->findByUser($userId);

        if ($profile === null) {
            return false;
        }

        return match ($badge) {
            Badge::FirstPost => $profile->threadCount >= 1 || $profile->postCount >= 1,
            Badge::FirstAnswer, Badge::Solver => $this->hasAcceptedSolution($userId),
            Badge::Helpful => $this->hasHighVoteScore($userId),
            Badge::PopularThread => $this->hasPopularThread($userId),
            Badge::BugHunter, Badge::Multilingual => true, // Awarded directly by moderation/integration
            Badge::Contributor => $profile->reputationLevel()->value >= ReputationLevel::Contributor->value,
        };
    }

    public function award(string $userId, Badge $badge, ?string $tenantId = null): ?UserBadge
    {
        if (!$this->config->badges->enabled) {
            return null;
        }

        // Idempotent — skip if already awarded
        if ($this->userBadges->hasBadge($userId, $badge, $tenantId)) {
            return null;
        }

        if (!$this->evaluate($userId, $badge)) {
            return null;
        }

        $userBadge = UserBadge::award(
            id: UuidGenerator::v7(),
            userId: $userId,
            badge: $badge,
            tenantId: $tenantId,
        );

        $this->userBadges->save($userBadge);

        $this->events->dispatch(new BadgeAwarded(
            badgeId: $userBadge->id,
            userId: $userBadge->userId,
            badge: $userBadge->badge->value,
            tenantId: $userBadge->tenantId,
        ));

        return $userBadge;
    }

    public function revoke(string $userId, Badge $badge): void
    {
        $badges = $this->userBadges->findByUser($userId);

        foreach ($badges as $userBadge) {
            if ($userBadge->badge === $badge) {
                $this->userBadges->delete($userBadge);

                return;
            }
        }

        throw ForumException::notFound('UserBadge', "$userId:$badge->value");
    }

    public function getUserBadges(string $userId): array
    {
        return $this->userBadges->findByUser($userId);
    }

    public function hasBadge(string $userId, Badge $badge): bool
    {
        return $this->userBadges->hasBadge($userId, $badge);
    }

    private function hasAcceptedSolution(string $userId): bool
    {
        $posts = $this->posts->findByAuthor($userId, 1, 100);

        return array_any($posts->items, static fn(object $post): bool => $post->isSolution);
    }

    private function hasHighVoteScore(string $userId): bool
    {
        $posts = $this->posts->findByAuthor($userId, 1, 100);

        return array_any($posts->items, static fn(object $post): bool => $post->voteScore >= 5);
    }

    private function hasPopularThread(string $userId): bool
    {
        $threads = $this->threads->findByAuthor($userId, 1, 100);

        return array_any($threads->items, static fn(object $thread): bool => $thread->viewCount >= 100);
    }
}
