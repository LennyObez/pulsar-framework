<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Newsletter;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Newsletter campaign entity.
 *
 * Represents a composed email campaign with subject, HTML/text bodies,
 * scheduling, and aggregate analytics counters for open/click/bounce tracking.
 *
 * @psalm-api Public DTO returned from NewsletterCampaignRepositoryInterface;
 *            consumed by admin templates and dispatch jobs.
 */
#[Api(since: '1.0.0')]
final readonly class NewsletterCampaign
{
    /**
     * @param string $id UUIDv7
     * @param string|null $tenantId Tenant scope
     * @param string $subject Email subject line
     * @param string $bodyHtml HTML body content
     * @param string|null $bodyText Plain text fallback
     * @param string $locale Target locale for recipient matching
     * @param CampaignStatus $status Current campaign status
     * @param DateTimeImmutable|null $scheduledAt When the campaign is scheduled to send
     * @param DateTimeImmutable|null $sentAt When the campaign started sending
     * @param int $recipientCount Total recipients the campaign was sent to
     * @param int $openedCount Number of unique opens
     * @param int $clickedCount Number of unique clicks
     * @param int $bouncedCount Number of bounced deliveries
     * @param string|null $createdBy FK to auth_users for the campaign creator
     * @param DateTimeImmutable $createdAt Immutable creation timestamp
     * @param DateTimeImmutable $updatedAt Last modification timestamp
     */
    public function __construct(
        public string $id,
        public ?string $tenantId,
        public string $subject,
        public string $bodyHtml,
        public ?string $bodyText,
        public string $locale,
        public CampaignStatus $status,
        public ?DateTimeImmutable $scheduledAt,
        public ?DateTimeImmutable $sentAt,
        public int $recipientCount,
        public int $openedCount,
        public int $clickedCount,
        public int $bouncedCount,
        public ?string $createdBy,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    /**
     * Create a new draft campaign.
     */
    public static function create(
        string $id,
        string $subject,
        string $bodyHtml,
        string $locale,
        ?string $bodyText = null,
        ?string $tenantId = null,
        ?string $createdBy = null,
    ): self {
        $now = new DateTimeImmutable();

        return new self(
            id: $id,
            tenantId: $tenantId,
            subject: $subject,
            bodyHtml: $bodyHtml,
            bodyText: $bodyText,
            locale: $locale,
            status: CampaignStatus::Draft,
            scheduledAt: null,
            sentAt: null,
            recipientCount: 0,
            openedCount: 0,
            clickedCount: 0,
            bouncedCount: 0,
            createdBy: $createdBy,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    /**
     * Update campaign content (only allowed in Draft status).
     */
    public function update(
        string $subject,
        string $bodyHtml,
        ?string $bodyText,
        string $locale,
    ): self {
        return new self(
            id: $this->id,
            tenantId: $this->tenantId,
            subject: $subject,
            bodyHtml: $bodyHtml,
            bodyText: $bodyText,
            locale: $locale,
            status: $this->status,
            scheduledAt: $this->scheduledAt,
            sentAt: $this->sentAt,
            recipientCount: $this->recipientCount,
            openedCount: $this->openedCount,
            clickedCount: $this->clickedCount,
            bouncedCount: $this->bouncedCount,
            createdBy: $this->createdBy,
            createdAt: $this->createdAt,
            updatedAt: new DateTimeImmutable(),
        );
    }

    /**
     * Schedule the campaign for a future send.
     */
    public function schedule(DateTimeImmutable $scheduledAt): self
    {
        return new self(
            id: $this->id,
            tenantId: $this->tenantId,
            subject: $this->subject,
            bodyHtml: $this->bodyHtml,
            bodyText: $this->bodyText,
            locale: $this->locale,
            status: CampaignStatus::Scheduled,
            scheduledAt: $scheduledAt,
            sentAt: $this->sentAt,
            recipientCount: $this->recipientCount,
            openedCount: $this->openedCount,
            clickedCount: $this->clickedCount,
            bouncedCount: $this->bouncedCount,
            createdBy: $this->createdBy,
            createdAt: $this->createdAt,
            updatedAt: new DateTimeImmutable(),
        );
    }

    /**
     * Transition to sending status.
     */
    public function markSending(int $recipientCount): self
    {
        return new self(
            id: $this->id,
            tenantId: $this->tenantId,
            subject: $this->subject,
            bodyHtml: $this->bodyHtml,
            bodyText: $this->bodyText,
            locale: $this->locale,
            status: CampaignStatus::Sending,
            scheduledAt: $this->scheduledAt,
            sentAt: new DateTimeImmutable(),
            recipientCount: $recipientCount,
            openedCount: $this->openedCount,
            clickedCount: $this->clickedCount,
            bouncedCount: $this->bouncedCount,
            createdBy: $this->createdBy,
            createdAt: $this->createdAt,
            updatedAt: new DateTimeImmutable(),
        );
    }

    /**
     * Mark campaign as fully sent.
     */
    public function markSent(): self
    {
        return new self(
            id: $this->id,
            tenantId: $this->tenantId,
            subject: $this->subject,
            bodyHtml: $this->bodyHtml,
            bodyText: $this->bodyText,
            locale: $this->locale,
            status: CampaignStatus::Sent,
            scheduledAt: $this->scheduledAt,
            sentAt: $this->sentAt,
            recipientCount: $this->recipientCount,
            openedCount: $this->openedCount,
            clickedCount: $this->clickedCount,
            bouncedCount: $this->bouncedCount,
            createdBy: $this->createdBy,
            createdAt: $this->createdAt,
            updatedAt: new DateTimeImmutable(),
        );
    }

    /**
     * Cancel a scheduled campaign.
     */
    public function cancel(): self
    {
        return new self(
            id: $this->id,
            tenantId: $this->tenantId,
            subject: $this->subject,
            bodyHtml: $this->bodyHtml,
            bodyText: $this->bodyText,
            locale: $this->locale,
            status: CampaignStatus::Cancelled,
            scheduledAt: $this->scheduledAt,
            sentAt: $this->sentAt,
            recipientCount: $this->recipientCount,
            openedCount: $this->openedCount,
            clickedCount: $this->clickedCount,
            bouncedCount: $this->bouncedCount,
            createdBy: $this->createdBy,
            createdAt: $this->createdAt,
            updatedAt: new DateTimeImmutable(),
        );
    }

    public function isDraft(): bool
    {
        return $this->status === CampaignStatus::Draft;
    }

    public function isScheduled(): bool
    {
        return $this->status === CampaignStatus::Scheduled;
    }

    public function isSent(): bool
    {
        return $this->status === CampaignStatus::Sent;
    }

    public function isCancelled(): bool
    {
        return $this->status === CampaignStatus::Cancelled;
    }
}
