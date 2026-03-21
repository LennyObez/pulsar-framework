<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Thread;

use Pulsar\Api\Api;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Forum\Domain\ThreadStatus;
use Pulsar\Extension\Forum\Domain\ThreadType;

/**
 * Repository interface for the Thread aggregate root.
 * @api
 */
#[Api(since: '1.0.0')]
interface ThreadRepositoryInterface
{
    public function findById(string $id): ?Thread;

    /**
     * Find a thread by its URL slug.
     */
    public function findBySlug(string $slug, ?string $tenantId = null): ?Thread;

    /**
     * Find threads in a category with pagination.
     *
     * @return PaginationResult<Thread>
     */
    public function findByCategory(
        string $categoryId,
        int $page = 1,
        int $perPage = 25,
        ?ThreadStatus $status = null,
        ?ThreadType $type = null,
    ): PaginationResult;

    /**
     * Find threads by author with pagination.
     *
     * @return PaginationResult<Thread>
     */
    public function findByAuthor(
        string $authorId,
        int $page = 1,
        int $perPage = 25,
    ): PaginationResult;

    /**
     * Find threads tagged with a specific tag, with pagination.
     *
     * @return PaginationResult<Thread>
     */
    public function findByTag(
        string $tagId,
        int $page = 1,
        int $perPage = 25,
    ): PaginationResult;

    /**
     * Find the most recent threads across all categories.
     *
     * @return PaginationResult<Thread>
     */
    public function findRecent(
        int $page = 1,
        int $perPage = 25,
        ?string $tenantId = null,
    ): PaginationResult;

    /**
     * Search threads by title or body content.
     *
     * @return PaginationResult<Thread>
     */
    public function search(
        string $query,
        int $page = 1,
        int $perPage = 25,
        ?string $tenantId = null,
    ): PaginationResult;

    /**
     * @note The entity object is stale after this call: the database version is incremented server-side.
     *       Re-fetch via findById() if you need the updated version.
     */
    public function save(Thread $thread): void;

    public function delete(Thread $thread): void;

    /**
     * Atomically increment the aggregate vote score of a thread.
     */
    public function incrementVoteScore(string $id, int $delta): void;

    /**
     * Atomically increment the reply count and update last-activity timestamp.
     */
    public function incrementReplyCount(string $id, int $delta = 1): void;
}
