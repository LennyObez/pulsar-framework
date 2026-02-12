<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Result of verifying a purchase token against the store API.
 *
 * Returned by SubscriptionVerifierInterface::verify() after communicating
 * with Google Play or App Store servers.
 */
#[Api(since: '1.0.0')]
final readonly class VerificationResult
{
    /**
     * @param bool $isValid Whether the store confirmed the purchase as valid
     * @param DateTimeImmutable|null $expiresAt Subscription expiry from the store
     * @param DateTimeImmutable|null $gracePeriodUntil Grace period end if billing failed
     * @param string $productId Product/SKU as reported by the store
     * @param bool $autoRenewing Whether the subscription is set to auto-renew
     */
    public function __construct(
        public bool $isValid,
        public ?DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $gracePeriodUntil,
        public string $productId,
        public bool $autoRenewing,
    ) {}

    /**
     * Create a result indicating an invalid or unverifiable purchase.
     */
    public static function invalid(): self
    {
        return new self(
            isValid: false,
            expiresAt: null,
            gracePeriodUntil: null,
            productId: '',
            autoRenewing: false,
        );
    }
}
