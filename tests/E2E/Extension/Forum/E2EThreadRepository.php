<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E\Extension\Forum;

use Override;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Forum\Domain\ThreadStatus;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;

use function array_filter;
use function array_slice;
use function array_values;
use function count;
use function str_contains;

/**
 * @internal In-memory thread repository for E2E tests.
 */
final class E2EThreadRepository implements ThreadRepositoryInterface
{
    /** @var array<string, Thread> */
    private array $threads = [];

    /** @var array<string, list<string>> */
    private array $tagIndex = [];

    #[Override]
    public function findById(string $id): ?Thread
    {
        return $this->threads[$id] ?? null;
    }

    #[Override]
    public function findBySlug(string $slug, ?string $tenantId = null): ?Thread
    {
        foreach ($this->threads as $thread) {
            if ($thread->slug === $slug) {
                return $thread;
            }
        }

        return null;
    }

    #[Override]
    public function findByCategory(
        string $categoryId,
        int $page = 1,
        int $perPage = 25,
        ?ThreadStatus $status = null,
        ?ThreadType $type = null,
    ): PaginationResult {
        $items = array_filter($this->threads, static fn(Thread $t) => $t->categoryId === $categoryId && ($status === null || $t->status === $status));
        $items = array_values($items);
        $total = count($items);
        $offset = ($page - 1) * $perPage;
        $paged = array_slice($items, $offset, $perPage);

        return new PaginationResult(items: $paged, total: $total, hasMore: ($offset + $perPage) < $total, perPage: $perPage);
    }

    #[Override]
    public function findByAuthor(string $authorId, int $page = 1, int $perPage = 25): PaginationResult
    {
        $items = array_values(array_filter($this->threads, static fn(Thread $t) => $t->authorId === $authorId));
        $total = count($items);
        $offset = ($page - 1) * $perPage;
        $paged = array_slice($items, $offset, $perPage);

        return new PaginationResult(items: $paged, total: $total, hasMore: ($offset + $perPage) < $total, perPage: $perPage);
    }

    #[Override]
    public function findByTag(string $tagId, int $page = 1, int $perPage = 25): PaginationResult
    {
        /** @var list<Thread> */
        $items = [];

        if (isset($this->tagIndex[$tagId])) {
            foreach ($this->tagIndex[$tagId] as $threadId) {
                if (isset($this->threads[$threadId])) {
                    $items[] = $this->threads[$threadId];
                }
            }
        }

        $total = count($items);
        $offset = ($page - 1) * $perPage;
        $paged = array_slice($items, $offset, $perPage);

        return new PaginationResult(items: $paged, total: $total, hasMore: ($offset + $perPage) < $total, perPage: $perPage);
    }

    #[Override]
    public function findRecent(int $page = 1, int $perPage = 25, ?string $tenantId = null): PaginationResult
    {
        $items = array_values($this->threads);
        $total = count($items);
        $offset = ($page - 1) * $perPage;
        $paged = array_slice($items, $offset, $perPage);

        return new PaginationResult(items: $paged, total: $total, hasMore: ($offset + $perPage) < $total, perPage: $perPage);
    }

    #[Override]
    public function search(string $query, int $page = 1, int $perPage = 25, ?string $tenantId = null): PaginationResult
    {
        $items = array_values(array_filter(
            $this->threads,
            static fn(Thread $t) => str_contains($t->title, $query),
        ));
        $total = count($items);
        $offset = ($page - 1) * $perPage;
        $paged = array_slice($items, $offset, $perPage);

        return new PaginationResult(items: $paged, total: $total, hasMore: ($offset + $perPage) < $total, perPage: $perPage);
    }

    #[Override]
    public function save(Thread $thread): void
    {
        $this->threads[$thread->id] = $thread;
    }

    #[Override]
    public function delete(Thread $thread): void
    {
        unset($this->threads[$thread->id]);
    }

    #[Override]
    public function incrementVoteScore(string $id, int $delta): void
    {
        if (isset($this->threads[$id])) {
            $this->threads[$id] = $this->threads[$id]->updateVoteScore($delta);
        }
    }

    #[Override]
    public function incrementReplyCount(string $id, int $delta = 1): void
    {
        if (!isset($this->threads[$id])) {
            return;
        }

        if ($delta > 0) {
            for ($i = 0; $i < $delta; $i++) {
                $this->threads[$id] = $this->threads[$id]->incrementReplyCount();
            }
        }
    }

    /**
     * Index a thread under a tag for findByTag() lookups.
     *
     * @internal Test infrastructure only.
     */
    public function indexTag(string $tagId, string $threadId): void
    {
        $this->tagIndex[$tagId][] = $threadId;
    }

    /**
     * Remove a thread from a tag index for findByTag() lookups.
     *
     * @internal Test infrastructure only.
     */
    public function deindexTag(string $tagId, string $threadId): void
    {
        if (!isset($this->tagIndex[$tagId])) {
            return;
        }

        $this->tagIndex[$tagId] = array_values(array_filter(
            $this->tagIndex[$tagId],
            static fn(string $id) => $id !== $threadId,
        ));
    }
}
