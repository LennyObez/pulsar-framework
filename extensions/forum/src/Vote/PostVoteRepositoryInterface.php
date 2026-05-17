<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Vote;

use Pulsar\Api\Api;

/**
 * Repository interface for post votes.
 * @api
 */
#[Api(since: '1.0.0')]
interface PostVoteRepositoryInterface
{
    public function findById(string $id): ?PostVote;

    /**
     * Find a user's existing vote on a post.
     */
    public function findByUserAndPost(string $userId, string $postId): ?PostVote;

    /**
     * Calculate the aggregate vote score for a post.
     */
    public function scoreForPost(string $postId): int;

    public function save(PostVote $vote): void;

    public function delete(PostVote $vote): void;
}
