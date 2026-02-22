<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Comments;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Exception\CmsException;

/**
 * Comment entity — represents a user or guest comment on a content item.
 *
 * Supports threaded replies via parent_id, authenticated and guest authors,
 * and a time-limited edit window after submission.
 */
#[Api(since: '1.0.0')]
final readonly class Comment
{
    /**
     * @param string $id UUIDv7
     * @param string|null $tenantId UUIDv7, nullable when tenancy disabled
     * @param string $contentId UUIDv7 FK content
     * @param string|null $parentId UUIDv7 self-ref for threaded replies
     * @param string|null $authorId UUIDv7 for authenticated users
     * @param string|null $guestName Display name for guest commenters
     * @param string|null $guestEmail Email for guest commenters
     * @param string $body Sanitized HTML body
     * @param ModerationStatus $status Current moderation status
     * @param string $ipHash Hashed IP address for anti-abuse
     * @param string $userAgentHash Hashed user agent for anti-abuse
     * @param DateTimeImmutable|null $editedAt When the comment was last edited
     * @param DateTimeImmutable|null $editWindowExpiresAt Deadline for author edits
     * @param DataClassification $dataClassification Data classification level
     * @param DateTimeImmutable $createdAt Immutable creation timestamp
     * @param DateTimeImmutable|null $deletedAt Soft delete timestamp
     */
    public function __construct(
        public string $id,
        public ?string $tenantId,
        public string $contentId,
        public ?string $parentId,
        public ?string $authorId,
        public ?string $guestName,
        public ?string $guestEmail,
        public string $body,
        public ModerationStatus $status,
        public string $ipHash,
        public string $userAgentHash,
        public ?DateTimeImmutable $editedAt,
        public ?DateTimeImmutable $editWindowExpiresAt,
        public DataClassification $dataClassification,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $deletedAt,
    ) {}

    /**
     * Create a new guest comment (pending moderation by default).
     */
    public static function create(
        string $id,
        string $contentId,
        string $body,
        string $ipHash,
        string $userAgentHash,
        ?string $guestName = null,
        ?string $guestEmail = null,
        ?string $tenantId = null,
        ?string $parentId = null,
        DataClassification $dataClassification = DataClassification::Pii,
    ): self {
        $now = new DateTimeImmutable();

        return new self(
            id: $id,
            tenantId: $tenantId,
            contentId: $contentId,
            parentId: $parentId,
            authorId: null,
            guestName: $guestName,
            guestEmail: $guestEmail,
            body: $body,
            status: ModerationStatus::Pending,
            ipHash: $ipHash,
            userAgentHash: $userAgentHash,
            editedAt: null,
            editWindowExpiresAt: $now->modify('+15 minutes'),
            dataClassification: $dataClassification,
            createdAt: $now,
            deletedAt: null,
        );
    }

    /**
     * Create a new authenticated user comment.
     *
     * Authenticated comments can optionally be auto-approved.
     */
    public static function createAuthenticated(
        string $id,
        string $contentId,
        string $authorId,
        string $body,
        string $ipHash,
        string $userAgentHash,
        bool $autoApprove = false,
        ?string $tenantId = null,
        ?string $parentId = null,
        DataClassification $dataClassification = DataClassification::Pii,
    ): self {
        $now = new DateTimeImmutable();

        return new self(
            id: $id,
            tenantId: $tenantId,
            contentId: $contentId,
            parentId: $parentId,
            authorId: $authorId,
            guestName: null,
            guestEmail: null,
            body: $body,
            status: $autoApprove ? ModerationStatus::Approved : ModerationStatus::Pending,
            ipHash: $ipHash,
            userAgentHash: $userAgentHash,
            editedAt: null,
            editWindowExpiresAt: $now->modify('+15 minutes'),
            dataClassification: $dataClassification,
            createdAt: $now,
            deletedAt: null,
        );
    }

    /**
     * Transition to the given moderation status.
     *
     * @throws CmsException If the transition is invalid
     */
    public function moderate(ModerationStatus $target): self
    {
        if (!$this->status->canTransitionTo($target)) {
            throw CmsException::invalidTransition($this->status->value, $target->value);
        }

        return new self(
            id: $this->id,
            tenantId: $this->tenantId,
            contentId: $this->contentId,
            parentId: $this->parentId,
            authorId: $this->authorId,
            guestName: $this->guestName,
            guestEmail: $this->guestEmail,
            body: $this->body,
            status: $target,
            ipHash: $this->ipHash,
            userAgentHash: $this->userAgentHash,
            editedAt: $this->editedAt,
            editWindowExpiresAt: $this->editWindowExpiresAt,
            dataClassification: $this->dataClassification,
            createdAt: $this->createdAt,
            deletedAt: $this->deletedAt,
        );
    }

    /**
     * Edit the comment body within the edit window.
     *
     * @throws CmsException If the edit window has expired
     */
    public function edit(string $newBody): self
    {
        if (!$this->canEdit()) {
            throw CmsException::commentEditWindowExpired($this->id);
        }

        return new self(
            id: $this->id,
            tenantId: $this->tenantId,
            contentId: $this->contentId,
            parentId: $this->parentId,
            authorId: $this->authorId,
            guestName: $this->guestName,
            guestEmail: $this->guestEmail,
            body: $newBody,
            status: $this->status,
            ipHash: $this->ipHash,
            userAgentHash: $this->userAgentHash,
            editedAt: new DateTimeImmutable(),
            editWindowExpiresAt: $this->editWindowExpiresAt,
            dataClassification: $this->dataClassification,
            createdAt: $this->createdAt,
            deletedAt: $this->deletedAt,
        );
    }

    /**
     * Whether the comment can still be edited (within the edit window).
     */
    public function canEdit(): bool
    {
        if ($this->editWindowExpiresAt === null) {
            return false;
        }

        return new DateTimeImmutable() < $this->editWindowExpiresAt;
    }

    public function isPending(): bool
    {
        return $this->status === ModerationStatus::Pending;
    }

    public function isApproved(): bool
    {
        return $this->status === ModerationStatus::Approved;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}
