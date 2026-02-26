<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Exception\CmsException;

/**
 * Content aggregate root — represents any publishable content unit
 * (article, page, or custom type registered by CMS plugins).
 */
#[Api(since: '1.0.0')]
final readonly class Content
{
    /**
     * @param string $id UUIDv7
     * @param string|null $tenantId UUIDv7, nullable when tenancy disabled
     * @param ContentType $contentType Built-in content type
     * @param string $authorId UUIDv7
     * @param PublishingStatus $status Current publishing status
     * @param DateTimeImmutable|null $scheduledPublishAt When status is Scheduled
     * @param DateTimeImmutable|null $scheduledUnpublishAt Optional auto-archive
     * @param DateTimeImmutable|null $publishedAt Set on first publish
     * @param DateTimeImmutable $createdAt Immutable creation timestamp
     * @param DateTimeImmutable $updatedAt Auto-managed update timestamp
     * @param DateTimeImmutable|null $deletedAt Soft delete timestamp
     * @param string|null $template Theme template override
     * @param string|null $parentId UUIDv7, self-referential for page hierarchy
     * @param int $sortOrder Sibling ordering
     * @param CommentPolicy $commentPolicy Comment policy for this content
     * @param DataClassification $dataClassification Data classification level
     * @param int $version Optimistic concurrency version (incremented on each save)
     */
    public function __construct(
        public string $id,
        public ?string $tenantId,
        public ContentType $contentType,
        public string $authorId,
        public PublishingStatus $status,
        public ?DateTimeImmutable $scheduledPublishAt,
        public ?DateTimeImmutable $scheduledUnpublishAt,
        public ?DateTimeImmutable $publishedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $deletedAt,
        public ?string $template,
        public ?string $parentId,
        public int $sortOrder,
        public CommentPolicy $commentPolicy,
        public DataClassification $dataClassification,
        public int $version = 1,
    ) {}

    /**
     * Create a new draft content item.
     */
    public static function create(
        string $id,
        ContentType $contentType,
        string $authorId,
        ?string $tenantId = null,
        ?string $template = null,
        ?string $parentId = null,
        int $sortOrder = 0,
        CommentPolicy $commentPolicy = CommentPolicy::Inherit,
        DataClassification $dataClassification = DataClassification::Public,
    ): self {
        $now = new DateTimeImmutable();

        return new self(
            id: $id,
            tenantId: $tenantId,
            contentType: $contentType,
            authorId: $authorId,
            status: PublishingStatus::Draft,
            scheduledPublishAt: null,
            scheduledUnpublishAt: null,
            publishedAt: null,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
            template: $template,
            parentId: $parentId,
            sortOrder: $sortOrder,
            commentPolicy: $commentPolicy,
            dataClassification: $dataClassification,
        );
    }

    /**
     * Transition to Published status.
     *
     * @throws CmsException If transition is invalid
     */
    public function publish(bool $editorialWorkflow = false): self
    {
        if (!$this->status->canTransitionTo(PublishingStatus::Published, $editorialWorkflow)) {
            throw CmsException::invalidTransition($this->status->value, PublishingStatus::Published->value);
        }

        $now = new DateTimeImmutable();

        return clone($this, [
            'status' => PublishingStatus::Published,
            'scheduledPublishAt' => null,
            'publishedAt' => $this->publishedAt ?? $now,
            'updatedAt' => $now,
        ]);
    }

    /**
     * Transition to Archived status.
     *
     * @throws CmsException If transition is invalid
     */
    public function archive(): self
    {
        if (!$this->status->canTransitionTo(PublishingStatus::Archived)) {
            throw CmsException::invalidTransition($this->status->value, PublishingStatus::Archived->value);
        }

        return clone($this, [
            'status' => PublishingStatus::Archived,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Restore archived content back to Draft.
     *
     * @throws CmsException If transition is invalid
     */
    public function restore(): self
    {
        if (!$this->status->canTransitionTo(PublishingStatus::Draft)) {
            throw CmsException::invalidTransition($this->status->value, PublishingStatus::Draft->value);
        }

        return clone($this, [
            'status' => PublishingStatus::Draft,
            'scheduledPublishAt' => null,
            'scheduledUnpublishAt' => null,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Schedule content for future publication.
     *
     * @throws CmsException If transition is invalid
     */
    public function schedule(DateTimeImmutable $publishAt, bool $editorialWorkflow = false): self
    {
        if (!$this->status->canTransitionTo(PublishingStatus::Scheduled, $editorialWorkflow)) {
            throw CmsException::invalidTransition($this->status->value, PublishingStatus::Scheduled->value);
        }

        return clone($this, [
            'status' => PublishingStatus::Scheduled,
            'scheduledPublishAt' => $publishAt,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Submit content for editorial review.
     *
     * @throws CmsException If transition is invalid
     */
    public function submitForReview(): self
    {
        if (!$this->status->canTransitionTo(PublishingStatus::InReview, editorialWorkflow: true)) {
            throw CmsException::invalidTransition($this->status->value, PublishingStatus::InReview->value);
        }

        return clone($this, [
            'status' => PublishingStatus::InReview,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Approve content in editorial review.
     *
     * @throws CmsException If transition is invalid
     */
    public function approve(): self
    {
        if (!$this->status->canTransitionTo(PublishingStatus::Approved, editorialWorkflow: true)) {
            throw CmsException::invalidTransition($this->status->value, PublishingStatus::Approved->value);
        }

        return clone($this, [
            'status' => PublishingStatus::Approved,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Reject content in editorial review, returning it to Draft.
     *
     * @throws CmsException If transition is invalid
     */
    public function reject(): self
    {
        if (!$this->status->canTransitionTo(PublishingStatus::Draft, editorialWorkflow: true)) {
            throw CmsException::invalidTransition($this->status->value, PublishingStatus::Draft->value);
        }

        return clone($this, [
            'status' => PublishingStatus::Draft,
            'scheduledPublishAt' => null,
            'scheduledUnpublishAt' => null,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Set or clear the parent content for hierarchy.
     */
    public function setParent(?string $parentId): self
    {
        return clone($this, [
            'parentId' => $parentId,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    public function isPublished(): bool
    {
        return $this->status === PublishingStatus::Published;
    }

    public function isDraft(): bool
    {
        return $this->status === PublishingStatus::Draft;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}
