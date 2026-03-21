<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Thread;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Forum\Domain\ThreadStatus;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\Exception\ForumException;

/**
 * Thread aggregate root: represents a discussion thread in the forum.
 *
 * Supports typed discussions (Q&A, bug report, feature request, etc.),
 * status transitions (open/closed/locked), pinning, and solution marking.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Thread
{
    /**
     * @param string $id UUIDv7
     * @param string|null $tenantId UUIDv7, nullable when tenancy disabled
     * @param string $categoryId UUIDv7 FK category
     * @param string $authorId UUIDv7 FK user
     * @param string $title Thread title
     * @param string $slug URL-safe identifier
     * @param ThreadType $type Thread content type
     * @param ThreadStatus $status Current lifecycle status
     * @param bool $isPinned Whether the thread is pinned to the top
     * @param bool $isLocked Whether replies are disabled
     * @param string|null $solvedPostId UUIDv7 FK post marked as accepted solution
     * @param int $replyCount Denormalized reply count
     * @param int $viewCount Denormalized view count
     * @param int $voteScore Denormalized aggregate vote score
     * @param DateTimeImmutable|null $lastActivityAt Timestamp of the most recent reply or edit
     * @param string $ipHash Hashed IP address for anti-abuse
     * @param string $userAgentHash Hashed user agent for anti-abuse
     * @param DateTimeImmutable $createdAt Immutable creation timestamp
     * @param DateTimeImmutable $updatedAt Auto-managed update timestamp
     * @param DateTimeImmutable|null $deletedAt Soft delete timestamp
     * @param int $version Optimistic concurrency version
     */
    public function __construct(
        public string $id,
        public ?string $tenantId,
        public string $categoryId,
        public string $authorId,
        public string $title,
        public string $slug,
        public ThreadType $type,
        public ThreadStatus $status,
        public bool $isPinned,
        public bool $isLocked,
        public ?string $solvedPostId,
        public int $replyCount,
        public int $viewCount,
        public int $voteScore,
        public ?DateTimeImmutable $lastActivityAt,
        public string $ipHash,
        public string $userAgentHash,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $deletedAt,
        public int $version = 1,
    ) {}

    /**
     * Create a new open thread.
     */
    public static function create(
        string $id,
        string $categoryId,
        string $authorId,
        string $title,
        string $slug,
        ThreadType $type,
        string $ipHash,
        string $userAgentHash,
        ?string $tenantId = null,
    ): self {
        $now = new DateTimeImmutable();

        return new self(
            id: $id,
            tenantId: $tenantId,
            categoryId: $categoryId,
            authorId: $authorId,
            title: $title,
            slug: $slug,
            type: $type,
            status: ThreadStatus::Open,
            isPinned: false,
            isLocked: false,
            solvedPostId: null,
            replyCount: 0,
            viewCount: 0,
            voteScore: 0,
            lastActivityAt: $now,
            ipHash: $ipHash,
            userAgentHash: $userAgentHash,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
        );
    }

    /**
     * Update the thread title and slug.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function editTitle(string $title, string $slug): self
    {
        return clone($this, [
            'title' => $title,
            'slug' => $slug,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Move thread to a different category.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function moveToCategory(string $categoryId): self
    {
        return clone($this, [
            'categoryId' => $categoryId,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Close the thread.
     *
     * @throws ForumException If the transition is invalid
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function close(): self
    {
        if (!$this->status->canTransitionTo(ThreadStatus::Closed)) {
            throw ForumException::invalidTransition($this->status->value, ThreadStatus::Closed->value);
        }

        return clone($this, [
            'status' => ThreadStatus::Closed,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Reopen a closed or locked thread.
     *
     * @throws ForumException If the transition is invalid
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function reopen(): self
    {
        if (!$this->status->canTransitionTo(ThreadStatus::Open)) {
            throw ForumException::invalidTransition($this->status->value, ThreadStatus::Open->value);
        }

        return clone($this, [
            'status' => ThreadStatus::Open,
            'isLocked' => false,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Lock the thread: prevents new replies.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function lock(): self
    {
        return clone($this, [
            'isLocked' => true,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Unlock the thread: allows new replies.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function unlock(): self
    {
        return clone($this, [
            'isLocked' => false,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Pin the thread to the top of its category listing.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function pin(): self
    {
        return clone($this, [
            'isPinned' => true,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Unpin the thread from the top of its category listing.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function unpin(): self
    {
        return clone($this, [
            'isPinned' => false,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Mark a post as the accepted solution for this thread.
     *
     * @throws ForumException If the thread type does not support solutions
     * @throws ForumException If the thread already has a solution
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function solve(string $postId): self
    {
        if (!$this->type->supportsSolution()) {
            throw ForumException::invalidTransition($this->type->value, 'solved');
        }

        if ($this->solvedPostId !== null) {
            throw ForumException::alreadyResolved($this->id);
        }

        return clone($this, [
            'solvedPostId' => $postId,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Increment the reply count and update last activity timestamp.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function incrementReplyCount(): self
    {
        return clone($this, [
            'replyCount' => $this->replyCount + 1,
            'lastActivityAt' => new DateTimeImmutable(),
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Increment the view count.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function incrementViewCount(): self
    {
        return clone($this, [
            'viewCount' => $this->viewCount + 1,
        ]);
    }

    /**
     * Update the aggregate vote score.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function updateVoteScore(int $delta): self
    {
        return clone($this, [
            'voteScore' => $this->voteScore + $delta,
        ]);
    }

    public function isOpen(): bool
    {
        return $this->status === ThreadStatus::Open;
    }

    public function isSolved(): bool
    {
        return $this->solvedPostId !== null;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}
