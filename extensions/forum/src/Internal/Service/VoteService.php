<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Internal\Service;

use Pulsar\Api\Internal;
use Pulsar\Database\Exception\DatabaseException;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Forum\Badge\BadgeServiceInterface;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Domain\Badge;
use Pulsar\Extension\Forum\Domain\VoteDirection;
use Pulsar\Extension\Forum\Event\VoteCast;
use Pulsar\Extension\Forum\Event\VoteRemoved;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Service\ReputationServiceInterface;
use Pulsar\Extension\Forum\Service\VoteServiceInterface;
use Pulsar\Extension\Forum\Support\UuidGenerator;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Extension\Forum\Vote\PostVote;
use Pulsar\Extension\Forum\Vote\PostVoteRepositoryInterface;
use Pulsar\Extension\Forum\Vote\ThreadVote;
use Pulsar\Extension\Forum\Vote\ThreadVoteRepositoryInterface;

/**
 * Voting service implementation: casts and removes votes with score aggregation.
 */
#[Internal(reason: 'Use VoteServiceInterface for public API')]
final readonly class VoteService implements VoteServiceInterface
{
    public function __construct(
        private ThreadVoteRepositoryInterface $threadVotes,
        private PostVoteRepositoryInterface $postVotes,
        private ThreadRepositoryInterface $threads,
        private PostRepositoryInterface $posts,
        private ForumProfileRepositoryInterface $profiles,
        private ReputationServiceInterface $reputationService,
        private BadgeServiceInterface $badgeService,
        private EventDispatcherInterface $events,
        private ForumConfig $config,
    ) {}

    public function castThreadVote(
        string $userId,
        string $threadId,
        VoteDirection $direction,
        ?string $tenantId = null,
    ): ThreadVote {
        $existing = $this->threadVotes->findByUserAndThread($userId, $threadId);

        if ($existing !== null) {
            throw ForumException::duplicateVote($userId, $threadId);
        }

        $thread = $this->threads->findById($threadId);

        if ($thread === null) {
            throw ForumException::notFound('Thread', $threadId);
        }

        // C-3: Prevent self-voting
        if ($userId === $thread->authorId) {
            throw ForumException::selfVote();
        }

        // C-4: Enforce minimum reputation for downvotes
        if ($direction === VoteDirection::Down) {
            $this->assertCanDownvote($userId);
        }

        $vote = ThreadVote::cast(
            id: UuidGenerator::v7(),
            userId: $userId,
            threadId: $threadId,
            value: $direction,
            tenantId: $tenantId,
        );

        try {
            $this->threadVotes->save($vote);
        } catch (DatabaseException) {
            throw ForumException::duplicateVote($userId, $threadId);
        }

        // C-1: Atomic vote score increment
        $this->threads->incrementVoteScore($threadId, $direction->value);

        // Award reputation to the thread author
        $reputationPoints = $direction === VoteDirection::Up
            ? $this->config->reputation->pointsPerUpvote
            : $this->config->reputation->pointsPerDownvote;

        $this->reputationService->addReputation(
            $thread->authorId,
            $reputationPoints,
            'thread_vote_received',
        );

        $this->events->dispatch(new VoteCast(
            voteId: $vote->id,
            targetType: 'thread',
            targetId: $threadId,
            voterId: $userId,
            direction: $direction,
            targetAuthorId: $thread->authorId,
            tenantId: $tenantId,
        ));

        return $vote;
    }

    public function castPostVote(
        string $userId,
        string $postId,
        VoteDirection $direction,
        ?string $tenantId = null,
    ): PostVote {
        $existing = $this->postVotes->findByUserAndPost($userId, $postId);

        if ($existing !== null) {
            throw ForumException::duplicateVote($userId, $postId);
        }

        $post = $this->posts->findById($postId);

        if ($post === null) {
            throw ForumException::notFound('Post', $postId);
        }

        // C-3: Prevent self-voting
        if ($userId === $post->authorId) {
            throw ForumException::selfVote();
        }

        // C-4: Enforce minimum reputation for downvotes
        if ($direction === VoteDirection::Down) {
            $this->assertCanDownvote($userId);
        }

        $vote = PostVote::cast(
            id: UuidGenerator::v7(),
            userId: $userId,
            postId: $postId,
            value: $direction,
            tenantId: $tenantId,
        );

        try {
            $this->postVotes->save($vote);
        } catch (DatabaseException) {
            throw ForumException::duplicateVote($userId, $postId);
        }

        // C-1: Atomic vote score increment
        $this->posts->incrementVoteScore($postId, $direction->value);

        // Award reputation to the post author
        $reputationPoints = $direction === VoteDirection::Up
            ? $this->config->reputation->pointsPerUpvote
            : $this->config->reputation->pointsPerDownvote;

        $this->reputationService->addReputation(
            $post->authorId,
            $reputationPoints,
            'post_vote_received',
        );

        $this->events->dispatch(new VoteCast(
            voteId: $vote->id,
            targetType: 'post',
            targetId: $postId,
            voterId: $userId,
            direction: $direction,
            targetAuthorId: $post->authorId,
            tenantId: $tenantId,
        ));

        // Evaluate Helpful badge: use post-increment score ($post->voteScore is pre-save)
        if ($post->voteScore + 1 >= 5 && $direction === VoteDirection::Up) {
            $this->badgeService->award($post->authorId, Badge::Helpful, $tenantId);
        }

        return $vote;
    }

    public function removeThreadVote(string $userId, string $threadId): void
    {
        $vote = $this->threadVotes->findByUserAndThread($userId, $threadId);

        if ($vote === null) {
            throw ForumException::notFound('ThreadVote', "$userId:$threadId");
        }

        $thread = $this->threads->findById($threadId);

        if ($thread === null) {
            throw ForumException::notFound('Thread', $threadId);
        }

        $this->threadVotes->delete($vote);

        // C-1: Atomic reversal of vote score
        $this->threads->incrementVoteScore($threadId, -$vote->value->value);

        // Reverse reputation from the thread author
        $reputationPoints = $vote->value === VoteDirection::Up
            ? -$this->config->reputation->pointsPerUpvote
            : -$this->config->reputation->pointsPerDownvote;

        $this->reputationService->addReputation(
            $thread->authorId,
            $reputationPoints,
            'thread_vote_removed',
        );

        $this->events->dispatch(new VoteRemoved(
            voteId: $vote->id,
            targetType: 'thread',
            targetId: $threadId,
            voterId: $userId,
            previousDirection: $vote->value,
            targetAuthorId: $thread->authorId,
            tenantId: $vote->tenantId,
        ));
    }

    public function removePostVote(string $userId, string $postId): void
    {
        $vote = $this->postVotes->findByUserAndPost($userId, $postId);

        if ($vote === null) {
            throw ForumException::notFound('PostVote', "$userId:$postId");
        }

        $post = $this->posts->findById($postId);

        if ($post === null) {
            throw ForumException::notFound('Post', $postId);
        }

        $this->postVotes->delete($vote);

        // C-1: Atomic reversal of vote score
        $this->posts->incrementVoteScore($postId, -$vote->value->value);

        // Reverse reputation from the post author
        $reputationPoints = $vote->value === VoteDirection::Up
            ? -$this->config->reputation->pointsPerUpvote
            : -$this->config->reputation->pointsPerDownvote;

        $this->reputationService->addReputation(
            $post->authorId,
            $reputationPoints,
            'post_vote_removed',
        );

        $this->events->dispatch(new VoteRemoved(
            voteId: $vote->id,
            targetType: 'post',
            targetId: $postId,
            voterId: $userId,
            previousDirection: $vote->value,
            targetAuthorId: $post->authorId,
            tenantId: $vote->tenantId,
        ));
    }

    /**
     * Verify the voter has enough reputation to downvote.
     *
     * @throws ForumException If the voter's reputation is below the threshold
     */
    private function assertCanDownvote(string $userId): void
    {
        $minReputation = $this->config->reputation->minReputationToDownvote;
        $voterProfile = $this->profiles->findByUser($userId);

        if ($voterProfile === null || $voterProfile->reputationScore < $minReputation) {
            throw ForumException::insufficientReputation($minReputation);
        }
    }
}
