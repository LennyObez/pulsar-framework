<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Newsletter;

use DateTimeImmutable;
use Pulsar\Api\Api;

use function bin2hex;
use function sodium_crypto_generichash;

/**
 * Newsletter subscriber entity.
 *
 * Represents an email address subscribed to the newsletter, with
 * double opt-in confirmation via hashed token and IP tracking for
 * compliance with anti-spam regulations (CAN-SPAM, GDPR).
 *
 * @psalm-api Public DTO returned from NewsletterSubscriberRepositoryInterface;
 *            consumed by subscription service and admin views.
 */
#[Api(since: '1.0.0')]
final readonly class NewsletterSubscriber
{
    /**
     * @param string $id UUIDv7
     * @param string $email Subscriber email address
     * @param string|null $userId FK to auth_users for authenticated subscribers
     * @param string $locale Preferred locale for campaigns
     * @param SubscriberStatus $status Current subscription status
     * @param string|null $confirmTokenHash BLAKE2b hash of the confirmation token
     * @param DateTimeImmutable|null $confirmedAt When the subscriber confirmed via email
     * @param DateTimeImmutable|null $unsubscribedAt When the subscriber opted out
     * @param string $ipAddressHash BLAKE2b hash of the subscriber's IP for anti-abuse
     * @param string $source How the subscriber signed up (e.g., 'form', 'import', 'api')
     * @param string|null $tenantId Tenant scope (nullable for single-tenant)
     * @param DateTimeImmutable $createdAt Immutable creation timestamp
     */
    public function __construct(
        public string $id,
        public string $email,
        public ?string $userId,
        public string $locale,
        public SubscriberStatus $status,
        public ?string $confirmTokenHash,
        public ?DateTimeImmutable $confirmedAt,
        public ?DateTimeImmutable $unsubscribedAt,
        public string $ipAddressHash,
        public string $source,
        public ?string $tenantId,
        public DateTimeImmutable $createdAt,
    ) {}

    /**
     * Create a new pending subscriber with hashed IP and generated ID.
     */
    public static function create(
        string $id,
        string $email,
        string $locale,
        string $ipAddress,
        string $source,
        ?string $userId = null,
        ?string $tenantId = null,
        ?string $confirmTokenHash = null,
    ): self {
        return new self(
            id: $id,
            email: $email,
            userId: $userId,
            locale: $locale,
            status: SubscriberStatus::Pending,
            confirmTokenHash: $confirmTokenHash,
            confirmedAt: null,
            unsubscribedAt: null,
            ipAddressHash: bin2hex(sodium_crypto_generichash($ipAddress)),
            source: $source,
            tenantId: $tenantId,
            createdAt: new DateTimeImmutable(),
        );
    }

    /**
     * Confirm the subscription after double opt-in verification.
     */
    public function confirm(): self
    {
        return new self(
            id: $this->id,
            email: $this->email,
            userId: $this->userId,
            locale: $this->locale,
            status: SubscriberStatus::Confirmed,
            confirmTokenHash: null,
            confirmedAt: new DateTimeImmutable(),
            unsubscribedAt: $this->unsubscribedAt,
            ipAddressHash: $this->ipAddressHash,
            source: $this->source,
            tenantId: $this->tenantId,
            createdAt: $this->createdAt,
        );
    }

    /**
     * Unsubscribe the subscriber.
     */
    public function unsubscribe(): self
    {
        return new self(
            id: $this->id,
            email: $this->email,
            userId: $this->userId,
            locale: $this->locale,
            status: SubscriberStatus::Unsubscribed,
            confirmTokenHash: $this->confirmTokenHash,
            confirmedAt: $this->confirmedAt,
            unsubscribedAt: new DateTimeImmutable(),
            ipAddressHash: $this->ipAddressHash,
            source: $this->source,
            tenantId: $this->tenantId,
            createdAt: $this->createdAt,
        );
    }

    /**
     * Re-subscribe a previously unsubscribed address (resets to pending).
     */
    public function resubscribe(string $confirmTokenHash): self
    {
        return new self(
            id: $this->id,
            email: $this->email,
            userId: $this->userId,
            locale: $this->locale,
            status: SubscriberStatus::Pending,
            confirmTokenHash: $confirmTokenHash,
            confirmedAt: null,
            unsubscribedAt: null,
            ipAddressHash: $this->ipAddressHash,
            source: $this->source,
            tenantId: $this->tenantId,
            createdAt: $this->createdAt,
        );
    }

    public function isConfirmed(): bool
    {
        return $this->status === SubscriberStatus::Confirmed;
    }

    public function isPending(): bool
    {
        return $this->status === SubscriberStatus::Pending;
    }

    public function isUnsubscribed(): bool
    {
        return $this->status === SubscriberStatus::Unsubscribed;
    }
}
