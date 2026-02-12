<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Internal\Notification;

use Pulsar\Api\Internal;
use Pulsar\Extension\Forum\Badge\BadgeServiceInterface;
use Pulsar\Extension\Forum\Config\BadgeConfig;
use Pulsar\Extension\Forum\Domain\Badge;
use Pulsar\Extension\Forum\Domain\ReputationLevel;
use Pulsar\Extension\Forum\Event\PostAcceptedAsSolution;
use Pulsar\Extension\Forum\Event\PostCreated;
use Pulsar\Extension\Forum\Event\ReputationChanged;
use Pulsar\Extension\Forum\Event\VoteCast;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;

use function count;

/**
 * Listens to domain events and evaluates badge criteria for automatic awarding.
 *
 * When criteria are met the badge service handles idempotent awarding —
 * duplicate awards are silently skipped, so this evaluator can fire
 * aggressively without risk of double-granting.
 */
#[Internal(reason: 'Badge automation — internal event listener')]
final readonly class BadgeEvaluator
{
    public function __construct(
        private BadgeServiceInterface $badgeService,
        private PostRepositoryInterface $postRepository,
        private ThreadRepositoryInterface $threadRepository,
        private ForumProfileRepositoryInterface $profileRepository,
        private BadgeConfig $badgeConfig,
    ) {}

    /**
     * Route a domain event to the appropriate badge evaluation logic.
     */
    public function handleEvent(object $event): void
    {
        if (!$this->badgeConfig->enabled) {
            return;
        }

        match (true) {
            $event instanceof PostCreated => $this->onPostCreated($event),
            $event instanceof VoteCast => $this->onVoteCast($event),
            $event instanceof PostAcceptedAsSolution => $this->onPostAcceptedAsSolution($event),
            $event instanceof ReputationChanged => $this->onReputationChanged($event),
            default => null,
        };
    }

    private function onPostCreated(PostCreated $event): void
    {
        $this->evaluateFirstPost($event->authorId, $event->tenantId);
        $this->evaluateMultilingual($event->authorId, $event->tenantId);
    }

    private function onVoteCast(VoteCast $event): void
    {
        if ($event->targetType !== 'post' || $event->direction->value !== 1) {
            return;
        }

        $this->evaluateHelpful($event->targetAuthorId, $event->tenantId);
    }

    private function onPostAcceptedAsSolution(PostAcceptedAsSolution $event): void
    {
        $this->evaluateFirstAnswer($event->postAuthorId, $event->tenantId);
        $this->evaluateSolver($event->postAuthorId, $event->tenantId);
    }

    private function onReputationChanged(ReputationChanged $event): void
    {
        $this->evaluateContributor($event->userId, $event->tenantId);
    }

    /**
     * FirstPost: User has created at least one post.
     */
    private function evaluateFirstPost(string $userId, ?string $tenantId): void
    {
        if ($this->badgeService->hasBadge($userId, Badge::FirstPost)) {
            return;
        }

        $result = $this->postRepository->findByAuthor($userId, 1, 1);

        if ($result->total !== null && $result->total >= 1) {
            $this->badgeService->award($userId, Badge::FirstPost, $tenantId);
        }
    }

    /**
     * Multilingual: User has posted in threads across multiple categories
     * with distinct locale translations, meeting the configured threshold.
     *
     * Since posts do not carry a locale directly, we approximate by counting
     * the distinct categories the user has threads in. If the user participates
     * in enough distinct categories, we award the badge.
     */
    private function evaluateMultilingual(string $userId, ?string $tenantId): void
    {
        if ($this->badgeService->hasBadge($userId, Badge::Multilingual)) {
            return;
        }

        $threshold = $this->badgeConfig->multilingualLocaleThreshold;

        // Count distinct categories the user has authored threads in.
        $threads = $this->threadRepository->findByAuthor($userId, 1, 100);
        $distinctCategories = [];

        foreach ($threads->items as $thread) {
            $distinctCategories[$thread->categoryId] = true;
        }

        if (count($distinctCategories) >= $threshold) {
            $this->badgeService->award($userId, Badge::Multilingual, $tenantId);
        }
    }

    /**
     * Helpful: A user's post answers have received at least N upvotes total.
     *
     * Checks the target author's highest-scoring answer to see if any single
     * post has reached the helpful upvote threshold.
     */
    private function evaluateHelpful(string $userId, ?string $tenantId): void
    {
        if ($this->badgeService->hasBadge($userId, Badge::Helpful)) {
            return;
        }

        $threshold = $this->badgeConfig->helpfulUpvoteThreshold;

        // Scan the user's recent posts to find one meeting the threshold.
        $result = $this->postRepository->findByAuthor($userId, 1, 100);

        foreach ($result->items as $post) {
            if ($post->voteScore >= $threshold) {
                $this->badgeService->award($userId, Badge::Helpful, $tenantId);

                return;
            }
        }
    }

    /**
     * FirstAnswer: User has had at least one post accepted as a solution.
     */
    private function evaluateFirstAnswer(string $userId, ?string $tenantId): void
    {
        if ($this->badgeService->hasBadge($userId, Badge::FirstAnswer)) {
            return;
        }

        // The event itself confirms the post was just accepted — that's the first.
        $this->badgeService->award($userId, Badge::FirstAnswer, $tenantId);
    }

    /**
     * Solver: User has had N or more posts accepted as solutions.
     */
    private function evaluateSolver(string $userId, ?string $tenantId): void
    {
        if ($this->badgeService->hasBadge($userId, Badge::Solver)) {
            return;
        }

        $threshold = $this->badgeConfig->solverAcceptedAnswerThreshold;

        // Count accepted solutions among the user's posts.
        $result = $this->postRepository->findByAuthor($userId, 1, 100);
        $solutionCount = 0;

        foreach ($result->items as $post) {
            if ($post->isSolution) {
                $solutionCount++;
            }
        }

        if ($solutionCount >= $threshold) {
            $this->badgeService->award($userId, Badge::Solver, $tenantId);
        }
    }

    /**
     * Contributor: User has reached the Contributor reputation level.
     */
    private function evaluateContributor(string $userId, ?string $tenantId): void
    {
        if ($this->badgeService->hasBadge($userId, Badge::Contributor)) {
            return;
        }

        $profile = $this->profileRepository->findByUser($userId, $tenantId);

        if ($profile === null) {
            return;
        }

        if ($profile->reputationLevel()->value >= ReputationLevel::Contributor->value) {
            $this->badgeService->award($userId, Badge::Contributor, $tenantId);
        }
    }
}
