<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Vote;

use Pulsar\Api\Api;

/**
 * Repository interface for thread votes.
 * @api
 */
#[Api(since: '1.0.0')]
interface ThreadVoteRepositoryInterface
{
    public function findById(string $id): ?ThreadVote;

    /**
     * Find a user's existing vote on a thread.
     */
    public function findByUserAndThread(string $userId, string $threadId): ?ThreadVote;

    /**
     * Calculate the aggregate vote score for a thread.
     */
    public function scoreForThread(string $threadId): int;

    public function save(ThreadVote $vote): void;

    public function delete(ThreadVote $vote): void;
}
