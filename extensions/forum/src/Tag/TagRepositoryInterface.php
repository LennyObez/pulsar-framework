<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tag;

use Pulsar\Api\Api;

/**
 * Repository interface for forum tags.
 * @api
 */
#[Api(since: '1.0.0')]
interface TagRepositoryInterface
{
    public function findById(string $id): ?Tag;

    /**
     * Find a tag by its URL slug.
     */
    public function findBySlug(string $slug): ?Tag;

    /**
     * Find all tags, ordered by usage count descending.
     *
     * @return list<Tag>
     */
    public function findAll(): array;

    /**
     * Find tags associated with a specific thread.
     *
     * @return list<Tag>
     */
    public function findByThread(string $threadId): array;

    /**
     * Attach a tag to a thread.
     */
    public function attachToThread(string $tagId, string $threadId): void;

    /**
     * Detach a tag from a thread.
     */
    public function detachFromThread(string $tagId, string $threadId): void;

    /**
     * Find the most popular tags ordered by usage count descending.
     *
     * @return list<Tag>
     */
    public function findPopular(int $limit = 20): array;

    public function save(Tag $tag): void;

    public function delete(Tag $tag): void;
}
