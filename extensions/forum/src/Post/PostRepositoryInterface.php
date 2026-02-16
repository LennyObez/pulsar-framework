<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Post;

use Pulsar\Api\Api;
use Pulsar\Api\Pagination\PaginationResult;

/**
 * Repository interface for forum posts.
 */
#[Api(since: '1.0.0')]
interface PostRepositoryInterface
{
    public function findById(string $id): ?Post;

    /**
     * Find posts within a thread with pagination, ordered by creation date.
     *
     * @return PaginationResult<Post>
     */
    public function findByThread(
        string $threadId,
        int $page = 1,
        int $perPage = 20,
    ): PaginationResult;

    /**
     * Find posts by author with pagination.
     *
     * @return PaginationResult<Post>
     */
    public function findByAuthor(
        string $authorId,
        int $page = 1,
        int $perPage = 20,
    ): PaginationResult;

    /**
     * Count posts in a thread (non-deleted).
     */
    public function countByThread(string $threadId): int;

    /**
     * @note The entity object is stale after this call: the database version is incremented server-side.
     *       Re-fetch via findById() if you need the updated version.
     */
    public function save(Post $post): void;

    public function delete(Post $post): void;

    /**
     * Atomically increment the aggregate vote score of a post.
     */
    public function incrementVoteScore(string $id, int $delta): void;
}
