<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Domain;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

use function bin2hex;
use function random_bytes;

/**
 * Subscription entity representing a recurring billing agreement.
 *
 * Immutable - state transitions produce new instances via clone-with.
 * Supports web-based subscriptions (Stripe/PayPal/SEPA) and mobile
 * in-app purchases (App Store/Google Play).
 */
#[Api(since: '1.0.0')]
final readonly class Subscription
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $customerId,
        public string $planId,
        public SubscriptionStatus $status,
        public BillingCycle $billingCycle,
        public Money $amount,
        public ?string $gateway,
        public ?string $gatewaySubscriptionId,
        public ?string $purchaseTokenHash,
        public ?string $originalTransactionId,
        public ?MobileStore $mobileStore,
        public ?DateTimeImmutable $currentPeriodStart,
        public ?DateTimeImmutable $currentPeriodEnd,
        public ?DateTimeImmutable $trialEnd,
        public ?DateTimeImmutable $cancelledAt,
        public ?DateTimeImmutable $gracePeriodUntil,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public array $metadata = [],
    ) {}

    /**
     * Create a new web-based subscription.
     *
     * @param array<string, mixed> $metadata
     */
    #[NoDiscard]
    public static function create(
        string $customerId,
        string $planId,
        BillingCycle $billingCycle,
        Money $amount,
        string $gateway,
        ?string $gatewaySubscriptionId = null,
        ?DateTimeImmutable $trialEnd = null,
        array $metadata = [],
    ): self {
        $now = new DateTimeImmutable();

        return new self(
            id: bin2hex(random_bytes(16)),
            customerId: $customerId,
            planId: $planId,
            status: $trialEnd !== null ? SubscriptionStatus::Trialing : SubscriptionStatus::Active,
            billingCycle: $billingCycle,
            amount: $amount,
            gateway: $gateway,
            gatewaySubscriptionId: $gatewaySubscriptionId,
            purchaseTokenHash: null,
            originalTransactionId: null,
            mobileStore: null,
            currentPeriodStart: $now,
            currentPeriodEnd: $billingCycle->nextDate($now),
            trialEnd: $trialEnd,
            cancelledAt: null,
            gracePeriodUntil: null,
            createdAt: $now,
            updatedAt: $now,
            metadata: $metadata,
        );
    }

    /**
     * Create a subscription from a mobile in-app purchase.
     */
    #[NoDiscard]
    public static function createMobile(
        string $customerId,
        string $planId,
        MobileStore $store,
        string $purchaseTokenHash,
        string $originalTransactionId,
        ?DateTimeImmutable $expiresAt = null,
    ): self {
        $now = new DateTimeImmutable();

        return new self(
            id: bin2hex(random_bytes(16)),
            customerId: $customerId,
            planId: $planId,
            status: SubscriptionStatus::Active,
            billingCycle: BillingCycle::Monthly,
            amount: Money::zero(Currency::USD),
            gateway: null,
            gatewaySubscriptionId: null,
            purchaseTokenHash: $purchaseTokenHash,
            originalTransactionId: $originalTransactionId,
            mobileStore: $store,
            currentPeriodStart: $now,
            currentPeriodEnd: $expiresAt,
            trialEnd: null,
            cancelledAt: null,
            gracePeriodUntil: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    /**
     * Transition to a new status.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    #[NoDiscard]
    public function withStatus(
        SubscriptionStatus $status,
        ?DateTimeImmutable $currentPeriodEnd = null,
        ?DateTimeImmutable $gracePeriodUntil = null,
    ): self {
        return clone($this, [
            'status' => $status,
            'currentPeriodEnd' => $currentPeriodEnd ?? $this->currentPeriodEnd,
            'gracePeriodUntil' => $gracePeriodUntil ?? $this->gracePeriodUntil,
            'cancelledAt' => $status === SubscriptionStatus::Cancelled ? new DateTimeImmutable() : $this->cancelledAt,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Whether the customer currently has access to the subscribed service.
     */
    public function hasAccess(): bool
    {
        return match ($this->status) {
            SubscriptionStatus::Active,
            SubscriptionStatus::Trialing,
            SubscriptionStatus::GracePeriod,
            SubscriptionStatus::PastDue => true,
            SubscriptionStatus::Cancelled => $this->currentPeriodEnd !== null
                && $this->currentPeriodEnd > new DateTimeImmutable(),
            SubscriptionStatus::Expired,
            SubscriptionStatus::Paused,
            SubscriptionStatus::Revoked => false,
        };
    }

    public function isMobile(): bool
    {
        return $this->mobileStore !== null;
    }
}
