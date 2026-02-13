<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Subscriptions;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Subscriptions\Store;
use Pulsar\Extension\Subscriptions\Subscription;
use Pulsar\Extension\Subscriptions\SubscriptionStatus;

use function strlen;

final class SubscriptionEntityTest extends TestCase
{
    private static function makeSubscription(
        SubscriptionStatus $status = SubscriptionStatus::Active,
        ?DateTimeImmutable $expiresAt = null,
    ): Subscription {
        $now = new DateTimeImmutable();

        return new Subscription(
            id: 'sub-001',
            userId: 'user-1',
            store: Store::Google,
            productId: 'premium_monthly',
            plan: 'Premium Monthly',
            status: $status,
            purchaseTokenHash: 'hash-abc',
            rawReceiptEncrypted: null,
            originalTransactionId: 'txn-001',
            expiresAt: $expiresAt ?? new DateTimeImmutable('+30 days'),
            gracePeriodUntil: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    #[Test]
    public function createSetsActiveStatusAndGeneratesId(): void
    {
        $sub = Subscription::create(
            userId: 'user-1',
            store: Store::Google,
            productId: 'premium',
            plan: 'Premium',
            purchaseTokenHash: 'hash-abc',
            originalTransactionId: 'txn-001',
        );

        self::assertSame('user-1', $sub->userId);
        self::assertSame(Store::Google, $sub->store);
        self::assertSame('premium', $sub->productId);
        self::assertSame(SubscriptionStatus::Active, $sub->status);
        self::assertSame(32, strlen($sub->id)); // 16 random bytes = 32 hex chars
        self::assertNull($sub->gracePeriodUntil);
    }

    #[Test]
    public function createAcceptsOptionalExpiresAt(): void
    {
        $expiresAt = new DateTimeImmutable('+30 days');
        $sub = Subscription::create(
            userId: 'user-1',
            store: Store::Apple,
            productId: 'premium_annual',
            plan: 'Annual',
            purchaseTokenHash: 'hash-xyz',
            originalTransactionId: 'txn-002',
            expiresAt: $expiresAt,
        );

        self::assertSame($expiresAt, $sub->expiresAt);
    }

    #[Test]
    public function createAcceptsOptionalRawReceipt(): void
    {
        $sub = Subscription::create(
            userId: 'user-1',
            store: Store::Google,
            productId: 'premium',
            plan: 'Premium',
            purchaseTokenHash: 'hash-abc',
            originalTransactionId: 'txn-001',
            rawReceiptEncrypted: 'encrypted-receipt',
        );

        self::assertSame('encrypted-receipt', $sub->rawReceiptEncrypted);
    }

    #[Test]
    public function withStatusTransitionsToNewState(): void
    {
        $sub = self::makeSubscription(SubscriptionStatus::Active);
        $cancelled = $sub->withStatus(SubscriptionStatus::Cancelled);

        self::assertSame(SubscriptionStatus::Cancelled, $cancelled->status);
        self::assertSame($sub->id, $cancelled->id);
        self::assertSame($sub->userId, $cancelled->userId);
        // updatedAt should be later or same as original
        self::assertGreaterThanOrEqual($sub->updatedAt, $cancelled->updatedAt);
    }

    #[Test]
    public function withStatusUpdatesExpiresAt(): void
    {
        $sub = self::makeSubscription();
        $newExpiry = new DateTimeImmutable('+60 days');
        $renewed = $sub->withStatus(SubscriptionStatus::Active, expiresAt: $newExpiry);

        self::assertSame($newExpiry, $renewed->expiresAt);
    }

    #[Test]
    public function withStatusUpdatesGracePeriodUntil(): void
    {
        $sub = self::makeSubscription();
        $graceEnd = new DateTimeImmutable('+7 days');
        $grace = $sub->withStatus(SubscriptionStatus::GracePeriod, gracePeriodUntil: $graceEnd);

        self::assertSame(SubscriptionStatus::GracePeriod, $grace->status);
        self::assertSame($graceEnd, $grace->gracePeriodUntil);
    }

    #[Test]
    public function withReceiptUpdatesEncryptedReceipt(): void
    {
        $sub = self::makeSubscription();
        $updated = $sub->withReceipt('new-encrypted-receipt');

        self::assertSame('new-encrypted-receipt', $updated->rawReceiptEncrypted);
        self::assertSame($sub->id, $updated->id);
    }

    #[Test]
    public function withReceiptAcceptsNull(): void
    {
        $sub = self::makeSubscription();
        $cleared = $sub->withReceipt(null);

        self::assertNull($cleared->rawReceiptEncrypted);
    }

    #[Test]
    public function isActiveReturnsTrueForActiveStatus(): void
    {
        $sub = self::makeSubscription(SubscriptionStatus::Active);
        self::assertTrue($sub->isActive());
    }

    #[Test]
    public function isActiveReturnsFalseForNonActiveStatus(): void
    {
        $sub = self::makeSubscription(SubscriptionStatus::Expired);
        self::assertFalse($sub->isActive());
    }

    #[Test]
    public function isExpiredReturnsTrueForExpiredStatus(): void
    {
        $sub = self::makeSubscription(SubscriptionStatus::Expired);
        self::assertTrue($sub->isExpired());
    }

    #[Test]
    public function isExpiredReturnsFalseForActiveStatus(): void
    {
        $sub = self::makeSubscription(SubscriptionStatus::Active);
        self::assertFalse($sub->isExpired());
    }

    #[Test]
    public function isInGracePeriodReturnsTrueForGracePeriodStatus(): void
    {
        $sub = self::makeSubscription(SubscriptionStatus::GracePeriod);
        self::assertTrue($sub->isInGracePeriod());
    }

    #[Test]
    public function isRevokedReturnsTrueForRevokedStatus(): void
    {
        $sub = self::makeSubscription(SubscriptionStatus::Revoked);
        self::assertTrue($sub->isRevoked());
    }

    #[Test]
    public function hasAccessReturnsTrueForActiveStatus(): void
    {
        $sub = self::makeSubscription(SubscriptionStatus::Active);
        self::assertTrue($sub->hasAccess());
    }

    #[Test]
    public function hasAccessReturnsTrueForGracePeriod(): void
    {
        $sub = self::makeSubscription(SubscriptionStatus::GracePeriod);
        self::assertTrue($sub->hasAccess());
    }

    #[Test]
    public function hasAccessReturnsTrueForBillingRetry(): void
    {
        $sub = self::makeSubscription(SubscriptionStatus::BillingRetry);
        self::assertTrue($sub->hasAccess());
    }

    #[Test]
    public function hasAccessReturnsTrueForCancelledWithFutureExpiry(): void
    {
        $sub = self::makeSubscription(
            SubscriptionStatus::Cancelled,
            new DateTimeImmutable('+30 days'),
        );

        self::assertTrue($sub->hasAccess());
    }

    #[Test]
    public function hasAccessReturnsFalseForCancelledWithPastExpiry(): void
    {
        $sub = self::makeSubscription(
            SubscriptionStatus::Cancelled,
            new DateTimeImmutable('-1 day'),
        );

        self::assertFalse($sub->hasAccess());
    }

    #[Test]
    public function hasAccessReturnsFalseForCancelledWithNullExpiry(): void
    {
        $now = new DateTimeImmutable();
        $sub = new Subscription(
            id: 'sub-001',
            userId: 'user-1',
            store: Store::Google,
            productId: 'premium_monthly',
            plan: 'Premium',
            status: SubscriptionStatus::Cancelled,
            purchaseTokenHash: 'hash',
            rawReceiptEncrypted: null,
            originalTransactionId: 'txn',
            expiresAt: null,
            gracePeriodUntil: null,
            createdAt: $now,
            updatedAt: $now,
        );

        self::assertFalse($sub->hasAccess());
    }

    #[Test]
    public function hasAccessReturnsFalseForExpired(): void
    {
        $sub = self::makeSubscription(SubscriptionStatus::Expired);
        self::assertFalse($sub->hasAccess());
    }

    #[Test]
    public function hasAccessReturnsFalseForRevoked(): void
    {
        $sub = self::makeSubscription(SubscriptionStatus::Revoked);
        self::assertFalse($sub->hasAccess());
    }
}
