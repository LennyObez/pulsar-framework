<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions;

use DateTimeImmutable;
use Pulsar\Api\Api;

use function bin2hex;
use function random_bytes;

/**
 * Subscription entity representing a user's in-app purchase subscription.
 *
 * Immutable: state transitions produce new instances via clone-with.
 * The purchase token is stored as a one-way hash; the raw receipt is
 * encrypted at rest and may be null once verification is complete.
 */
#[Api(since: '1.0.0')]
final readonly class Subscription
{
    /**
     * @param string $id Hex-encoded random identifier
     * @param string $userId Application user who owns this subscription
     * @param Store $store Platform that issued the subscription
     * @param string $productId Store product/SKU identifier
     * @param string $plan Human-readable plan name (e.g. "premium_monthly")
     * @param SubscriptionStatus $status Current lifecycle state
     * @param string $purchaseTokenHash One-way hash of the purchase token
     * @param string|null $rawReceiptEncrypted Encrypted receipt for re-verification
     * @param string $originalTransactionId Store-assigned original transaction ID
     * @param DateTimeImmutable|null $expiresAt When the current billing period ends
     * @param DateTimeImmutable|null $gracePeriodUntil End of grace period (if applicable)
     * @param DateTimeImmutable $createdAt When this record was first created
     * @param DateTimeImmutable $updatedAt Last modification timestamp
     */
    public function __construct(
        public string $id,
        public string $userId,
        public Store $store,
        public string $productId,
        public string $plan,
        public SubscriptionStatus $status,
        public string $purchaseTokenHash,
        public ?string $rawReceiptEncrypted,
        public string $originalTransactionId,
        public ?DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $gracePeriodUntil,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    /**
     * Create a new subscription from a verified purchase.
     */
    public static function create(
        string $userId,
        Store $store,
        string $productId,
        string $plan,
        string $purchaseTokenHash,
        string $originalTransactionId,
        ?DateTimeImmutable $expiresAt = null,
        ?string $rawReceiptEncrypted = null,
    ): self {
        $now = new DateTimeImmutable();

        return new self(
            id: bin2hex(random_bytes(16)),
            userId: $userId,
            store: $store,
            productId: $productId,
            plan: $plan,
            status: SubscriptionStatus::Active,
            purchaseTokenHash: $purchaseTokenHash,
            rawReceiptEncrypted: $rawReceiptEncrypted,
            originalTransactionId: $originalTransactionId,
            expiresAt: $expiresAt,
            gracePeriodUntil: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    /**
     * Transition to a new status, optionally updating expiry and grace period.
     */
    public function withStatus(
        SubscriptionStatus $status,
        ?DateTimeImmutable $expiresAt = null,
        ?DateTimeImmutable $gracePeriodUntil = null,
    ): self {
        return clone($this, [
            'status' => $status,
            'expiresAt' => $expiresAt ?? $this->expiresAt,
            'gracePeriodUntil' => $gracePeriodUntil ?? $this->gracePeriodUntil,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Replace the encrypted receipt data (e.g. after re-verification).
     */
    public function withReceipt(?string $rawReceiptEncrypted): self
    {
        return clone($this, [
            'rawReceiptEncrypted' => $rawReceiptEncrypted,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    public function isActive(): bool
    {
        return $this->status === SubscriptionStatus::Active;
    }

    public function isExpired(): bool
    {
        return $this->status === SubscriptionStatus::Expired;
    }

    public function isInGracePeriod(): bool
    {
        return $this->status === SubscriptionStatus::GracePeriod;
    }

    public function isRevoked(): bool
    {
        return $this->status === SubscriptionStatus::Revoked;
    }

    /**
     * Whether the user should still have access to premium features.
     *
     * Access is granted during Active, GracePeriod, BillingRetry, and
     * Cancelled (until expiry) states.
     */
    public function hasAccess(): bool
    {
        return match ($this->status) {
            SubscriptionStatus::Active,
            SubscriptionStatus::GracePeriod,
            SubscriptionStatus::BillingRetry => true,
            SubscriptionStatus::Cancelled => $this->expiresAt !== null
                && $this->expiresAt > new DateTimeImmutable(),
            SubscriptionStatus::Expired,
            SubscriptionStatus::Revoked => false,
        };
    }
}
