<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Post;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Forum\Exception\ForumException;

/**
 * Forum post entity — represents a reply within a thread.
 *
 * Supports threaded replies via parentId, time-limited editing, solution
 * marking, and vote scoring. Stores both Markdown source and pre-rendered
 * sanitized HTML. Hashed IP and user agent for anti-abuse without PII retention.
 */
#[Api(since: '1.0.0')]
final readonly class Post
{
    /**
     * @param string $id UUIDv7
     * @param string|null $tenantId UUIDv7, nullable when tenancy disabled
     * @param string $threadId UUIDv7 FK thread
     * @param string|null $parentId UUIDv7 self-FK for threaded replies
     * @param string $authorId UUIDv7 FK user
     * @param string $body Markdown source
     * @param string $bodyHtml Pre-rendered sanitized HTML
     * @param bool $isSolution Whether this post is the accepted solution
     * @param int $voteScore Denormalized aggregate vote score
     * @param int $editCount Number of times the post has been edited
     * @param string|null $editedBy UUIDv7 FK user who last edited
     * @param string $ipHash Hashed IP address for anti-abuse
     * @param string $userAgentHash Hashed user agent for anti-abuse
     * @param DateTimeImmutable|null $editedAt When the post was last edited
     * @param DateTimeImmutable|null $editWindowExpiresAt Deadline for author edits
     * @param DateTimeImmutable $createdAt Immutable creation timestamp
     * @param DateTimeImmutable $updatedAt Auto-managed update timestamp
     * @param DateTimeImmutable|null $deletedAt Soft delete timestamp
     * @param int $version Optimistic concurrency version
     */
    public function __construct(
        public string $id,
        public ?string $tenantId,
        public string $threadId,
        public ?string $parentId,
        public string $authorId,
        public string $body,
        public string $bodyHtml,
        public bool $isSolution,
        public int $voteScore,
        public int $editCount,
        public ?string $editedBy,
        public string $ipHash,
        public string $userAgentHash,
        public ?DateTimeImmutable $editedAt,
        public ?DateTimeImmutable $editWindowExpiresAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $deletedAt,
        public int $version = 1,
    ) {}

    /**
     * Create a new post in a thread.
     */
    public static function create(
        string $id,
        string $threadId,
        string $authorId,
        string $body,
        string $bodyHtml,
        string $ipHash,
        string $userAgentHash,
        ?string $tenantId = null,
        ?string $parentId = null,
        int $editWindowMinutes = 30,
    ): self {
        $now = new DateTimeImmutable();
        $editWindowExpiry = $editWindowMinutes > 0
            ? $now->modify("+{$editWindowMinutes} minutes")
            : null;

        return new self(
            id: $id,
            tenantId: $tenantId,
            threadId: $threadId,
            parentId: $parentId,
            authorId: $authorId,
            body: $body,
            bodyHtml: $bodyHtml,
            isSolution: false,
            voteScore: 0,
            editCount: 0,
            editedBy: null,
            ipHash: $ipHash,
            userAgentHash: $userAgentHash,
            editedAt: null,
            editWindowExpiresAt: $editWindowExpiry,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
        );
    }

    /**
     * Edit the post body within the edit window.
     *
     * @throws ForumException If the edit window has expired
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function edit(string $newBody, string $newBodyHtml, string $editedBy): self
    {
        if (!$this->canEdit()) {
            throw ForumException::editWindowExpired($this->id);
        }

        $now = new DateTimeImmutable();

        return clone($this, [
            'body' => $newBody,
            'bodyHtml' => $newBodyHtml,
            'editCount' => $this->editCount + 1,
            'editedBy' => $editedBy,
            'editedAt' => $now,
            'updatedAt' => $now,
        ]);
    }

    /**
     * Mark this post as the accepted solution.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function markAsSolution(): self
    {
        return clone($this, [
            'isSolution' => true,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Remove the solution mark from this post.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function unmarkAsSolution(): self
    {
        return clone($this, [
            'isSolution' => false,
            'updatedAt' => new DateTimeImmutable(),
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

    /**
     * Whether the post can still be edited (within the edit window).
     */
    public function canEdit(): bool
    {
        if ($this->editWindowExpiresAt === null) {
            return true;
        }

        return new DateTimeImmutable() < $this->editWindowExpiresAt;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}
