<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E\Extension\Forum;

use Override;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Forum\Post\Post;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;

use function array_filter;
use function array_slice;
use function array_values;
use function count;

/**
 * @internal In-memory post repository for E2E tests.
 */
final class E2EPostRepository implements PostRepositoryInterface
{
    /** @var array<string, Post> */
    private array $posts = [];

    #[Override]
    public function findById(string $id): ?Post
    {
        return $this->posts[$id] ?? null;
    }

    #[Override]
    public function findByThread(string $threadId, int $page = 1, int $perPage = 20): PaginationResult
    {
        $items = array_values(array_filter($this->posts, static fn(Post $p) => $p->threadId === $threadId && !$p->isDeleted()));
        $total = count($items);
        $offset = ($page - 1) * $perPage;
        $paged = array_slice($items, $offset, $perPage);

        return new PaginationResult(items: $paged, total: $total, hasMore: ($offset + $perPage) < $total, perPage: $perPage);
    }

    #[Override]
    public function findByAuthor(string $authorId, int $page = 1, int $perPage = 20): PaginationResult
    {
        $items = array_values(array_filter($this->posts, static fn(Post $p) => $p->authorId === $authorId));
        $total = count($items);
        $offset = ($page - 1) * $perPage;
        $paged = array_slice($items, $offset, $perPage);

        return new PaginationResult(items: $paged, total: $total, hasMore: ($offset + $perPage) < $total, perPage: $perPage);
    }

    #[Override]
    public function countByThread(string $threadId): int
    {
        return count(array_filter($this->posts, static fn(Post $p) => $p->threadId === $threadId && !$p->isDeleted()));
    }

    #[Override]
    public function save(Post $post): void
    {
        $this->posts[$post->id] = $post;
    }

    #[Override]
    public function delete(Post $post): void
    {
        unset($this->posts[$post->id]);
    }

    #[Override]
    public function incrementVoteScore(string $id, int $delta): void
    {
        if (isset($this->posts[$id])) {
            $this->posts[$id] = $this->posts[$id]->updateVoteScore($delta);
        }
    }
}
