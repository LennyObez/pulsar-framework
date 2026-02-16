<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Newsletter;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Newsletter send entity: tracks the delivery status of a single
 * campaign email to a specific subscriber.
 *
 * One-to-one mapping: each (campaign_id, subscriber_id) pair is unique.
 */
#[Api(since: '1.0.0')]
final readonly class NewsletterSend
{
    /**
     * @param string $id UUIDv7
     * @param string $campaignId FK to newsletter campaigns
     * @param string $subscriberId FK to newsletter subscribers
     * @param SendStatus $status Current delivery status
     * @param DateTimeImmutable|null $sentAt When the email was dispatched
     * @param DateTimeImmutable|null $openedAt When the tracking pixel was loaded
     * @param DateTimeImmutable|null $clickedAt When a tracked link was clicked
     * @param string|null $bounceReason SMTP bounce reason if applicable
     */
    public function __construct(
        public string $id,
        public string $campaignId,
        public string $subscriberId,
        public SendStatus $status,
        public ?DateTimeImmutable $sentAt,
        public ?DateTimeImmutable $openedAt,
        public ?DateTimeImmutable $clickedAt,
        public ?string $bounceReason,
    ) {}

    /**
     * Create a new queued send record for a campaign-subscriber pair.
     */
    public static function create(
        string $id,
        string $campaignId,
        string $subscriberId,
    ): self {
        return new self(
            id: $id,
            campaignId: $campaignId,
            subscriberId: $subscriberId,
            status: SendStatus::Queued,
            sentAt: null,
            openedAt: null,
            clickedAt: null,
            bounceReason: null,
        );
    }

    /**
     * Mark as sent after the email was dispatched.
     */
    public function markSent(): self
    {
        return new self(
            id: $this->id,
            campaignId: $this->campaignId,
            subscriberId: $this->subscriberId,
            status: SendStatus::Sent,
            sentAt: new DateTimeImmutable(),
            openedAt: $this->openedAt,
            clickedAt: $this->clickedAt,
            bounceReason: $this->bounceReason,
        );
    }

    /**
     * Mark as delivered after delivery confirmation.
     */
    public function markDelivered(): self
    {
        return new self(
            id: $this->id,
            campaignId: $this->campaignId,
            subscriberId: $this->subscriberId,
            status: SendStatus::Delivered,
            sentAt: $this->sentAt,
            openedAt: $this->openedAt,
            clickedAt: $this->clickedAt,
            bounceReason: $this->bounceReason,
        );
    }

    /**
     * Record that the email was opened (tracking pixel loaded).
     */
    public function markOpened(): self
    {
        return new self(
            id: $this->id,
            campaignId: $this->campaignId,
            subscriberId: $this->subscriberId,
            status: $this->status,
            sentAt: $this->sentAt,
            openedAt: $this->openedAt ?? new DateTimeImmutable(),
            clickedAt: $this->clickedAt,
            bounceReason: $this->bounceReason,
        );
    }

    /**
     * Record that a tracked link was clicked.
     */
    public function markClicked(): self
    {
        return new self(
            id: $this->id,
            campaignId: $this->campaignId,
            subscriberId: $this->subscriberId,
            status: $this->status,
            sentAt: $this->sentAt,
            openedAt: $this->openedAt ?? new DateTimeImmutable(),
            clickedAt: $this->clickedAt ?? new DateTimeImmutable(),
            bounceReason: $this->bounceReason,
        );
    }

    /**
     * Mark as bounced with the SMTP reason.
     */
    public function markBounced(string $reason): self
    {
        return new self(
            id: $this->id,
            campaignId: $this->campaignId,
            subscriberId: $this->subscriberId,
            status: SendStatus::Bounced,
            sentAt: $this->sentAt,
            openedAt: $this->openedAt,
            clickedAt: $this->clickedAt,
            bounceReason: $reason,
        );
    }

    /**
     * Mark as failed (permanent delivery failure).
     */
    public function markFailed(string $reason): self
    {
        return new self(
            id: $this->id,
            campaignId: $this->campaignId,
            subscriberId: $this->subscriberId,
            status: SendStatus::Failed,
            sentAt: $this->sentAt,
            openedAt: $this->openedAt,
            clickedAt: $this->clickedAt,
            bounceReason: $reason,
        );
    }
}
