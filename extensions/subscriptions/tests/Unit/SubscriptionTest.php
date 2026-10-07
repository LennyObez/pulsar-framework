<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Subscriptions\Store;
use Pulsar\Extension\Subscriptions\Subscription;
use Pulsar\Extension\Subscriptions\SubscriptionStatus;

use function strlen;

#[CoversClass(Subscription::class)]
final class SubscriptionTest extends TestCase
{
    #[Test]
    public function createReturnsActiveSubscription(): void
    {
        $before = new DateTimeImmutable();

        $subscription = Subscription::create(
            userId: 'user-1',
            store: Store::Google,
            productId: 'com.example.premium',
            plan: 'premium_monthly',
            purchaseTokenHash: 'abc123hash',
            originalTransactionId: 'txn-001',
        );

        self::assertSame('user-1', $subscription->userId);
        self::assertSame(Store::Google, $subscription->store);
        self::assertSame('com.example.premium', $subscription->productId);
        self::assertSame('premium_monthly', $subscription->plan);
        self::assertSame(SubscriptionStatus::Active, $subscription->status);
        self::assertSame('abc123hash', $subscription->purchaseTokenHash);
        self::assertSame('txn-001', $subscription->originalTransactionId);
        self::assertNull($subscription->expiresAt);
        self::assertNull($subscription->gracePeriodUntil);
        self::assertNull($subscription->rawReceiptEncrypted);
        self::assertSame(32, strlen($subscription->id));
        self::assertGreaterThanOrEqual($before, $subscription->createdAt);
        self::assertGreaterThanOrEqual($before, $subscription->updatedAt);
    }

    #[Test]
    public function createAcceptsOptionalExpiresAt(): void
    {
        $expires = new DateTimeImmutable('+30 days');

        $subscription = Subscription::create(
            userId: 'user-2',
            store: Store::Apple,
            productId: 'com.example.yearly',
            plan: 'premium_yearly',
            purchaseTokenHash: 'hash456',
            originalTransactionId: 'txn-002',
            expiresAt: $expires,
        );

        self::assertSame($expires, $subscription->expiresAt);
    }

    #[Test]
    public function createAcceptsOptionalReceipt(): void
    {
        $subscription = Subscription::create(
            userId: 'user-3',
            store: Store::Google,
            productId: 'com.example.plan',
            plan: 'basic',
            purchaseTokenHash: 'hash789',
            originalTransactionId: 'txn-003',
            rawReceiptEncrypted: 'encrypted-data',
        );

        self::assertSame('encrypted-data', $subscription->rawReceiptEncrypted);
    }

    #[Test]
    public function createGeneratesUniqueIds(): void
    {
        $sub1 = Subscription::create(
            userId: 'user-1',
            store: Store::Google,
            productId: 'sku',
            plan: 'plan',
            purchaseTokenHash: 'h1',
            originalTransactionId: 't1',
        );

        $sub2 = Subscription::create(
            userId: 'user-1',
            store: Store::Google,
            productId: 'sku',
            plan: 'plan',
            purchaseTokenHash: 'h2',
            originalTransactionId: 't2',
        );

        self::assertNotSame($sub1->id, $sub2->id);
    }

    #[Test]
    public function withStatusReturnsNewInstanceWithUpdatedFields(): void
    {
        $original = $this->makeSubscription(SubscriptionStatus::Active);
        $newExpiry = new DateTimeImmutable('+60 days');
        $graceEnd = new DateTimeImmutable('+7 days');

        $updated = $original->withStatus(
            SubscriptionStatus::GracePeriod,
            expiresAt: $newExpiry,
            gracePeriodUntil: $graceEnd,
        );

        self::assertSame(SubscriptionStatus::GracePeriod, $updated->status);
        self::assertSame($newExpiry, $updated->expiresAt);
        self::assertSame($graceEnd, $updated->gracePeriodUntil);
        self::assertSame($original->id, $updated->id);
        self::assertSame($original->userId, $updated->userId);
        self::assertGreaterThanOrEqual($original->updatedAt, $updated->updatedAt);
    }

    #[Test]
    public function withStatusPreservesExistingDatesWhenNotOverridden(): void
    {
        $expires = new DateTimeImmutable('+30 days');
        $original = $this->makeSubscription(SubscriptionStatus::Active, expiresAt: $expires);

        $updated = $original->withStatus(SubscriptionStatus::Cancelled);

        self::assertSame(SubscriptionStatus::Cancelled, $updated->status);
        self::assertSame($expires, $updated->expiresAt);
    }

    #[Test]
    public function withReceiptReturnsNewInstanceWithUpdatedReceipt(): void
    {
        $original = $this->makeSubscription(SubscriptionStatus::Active);

        $updated = $original->withReceipt('new-encrypted-receipt');

        self::assertSame('new-encrypted-receipt', $updated->rawReceiptEncrypted);
        self::assertSame($original->id, $updated->id);
        self::assertGreaterThanOrEqual($original->updatedAt, $updated->updatedAt);
    }

    #[Test]
    public function withReceiptAcceptsNull(): void
    {
        $original = $this->makeSubscription(SubscriptionStatus::Active);
        $withReceipt = $original->withReceipt('some-data');
        $cleared = $withReceipt->withReceipt(null);

        self::assertNull($cleared->rawReceiptEncrypted);
    }

    #[Test]
    public function isActiveReturnsTrueOnlyForActiveStatus(): void
    {
        $active = $this->makeSubscription(SubscriptionStatus::Active);
        $expired = $this->makeSubscription(SubscriptionStatus::Expired);

        self::assertTrue($active->isActive());
        self::assertFalse($expired->isActive());
    }

    #[Test]
    public function isExpiredReturnsTrueOnlyForExpiredStatus(): void
    {
        $expired = $this->makeSubscription(SubscriptionStatus::Expired);
        $active = $this->makeSubscription(SubscriptionStatus::Active);

        self::assertTrue($expired->isExpired());
        self::assertFalse($active->isExpired());
    }

    #[Test]
    public function isInGracePeriodReturnsTrueOnlyForGracePeriodStatus(): void
    {
        $grace = $this->makeSubscription(SubscriptionStatus::GracePeriod);
        $active = $this->makeSubscription(SubscriptionStatus::Active);

        self::assertTrue($grace->isInGracePeriod());
        self::assertFalse($active->isInGracePeriod());
    }

    #[Test]
    public function isRevokedReturnsTrueOnlyForRevokedStatus(): void
    {
        $revoked = $this->makeSubscription(SubscriptionStatus::Revoked);
        $active = $this->makeSubscription(SubscriptionStatus::Active);

        self::assertTrue($revoked->isRevoked());
        self::assertFalse($active->isRevoked());
    }

    /**
     * @return iterable<string, array{SubscriptionStatus, bool}>
     */
    public static function hasAccessProvider(): iterable
    {
        yield 'active grants access' => [SubscriptionStatus::Active, true];
        yield 'grace period grants access' => [SubscriptionStatus::GracePeriod, true];
        yield 'billing retry grants access' => [SubscriptionStatus::BillingRetry, true];
        yield 'expired denies access' => [SubscriptionStatus::Expired, false];
        yield 'revoked denies access' => [SubscriptionStatus::Revoked, false];
    }

    #[Test]
    #[DataProvider('hasAccessProvider')]
    public function hasAccessReturnsExpectedForNonCancelledStatuses(
        SubscriptionStatus $status,
        bool $expected,
    ): void {
        $subscription = $this->makeSubscription($status);

        self::assertSame($expected, $subscription->hasAccess());
    }

    #[Test]
    public function cancelledWithFutureExpiryGrantsAccess(): void
    {
        $subscription = $this->makeSubscription(
            SubscriptionStatus::Cancelled,
            expiresAt: new DateTimeImmutable('+30 days'),
        );

        self::assertTrue($subscription->hasAccess());
    }

    #[Test]
    public function cancelledWithPastExpiryDeniesAccess(): void
    {
        $subscription = $this->makeSubscription(
            SubscriptionStatus::Cancelled,
            expiresAt: new DateTimeImmutable('-1 day'),
        );

        self::assertFalse($subscription->hasAccess());
    }

    #[Test]
    public function cancelledWithNullExpiryDeniesAccess(): void
    {
        $subscription = $this->makeSubscription(SubscriptionStatus::Cancelled);

        self::assertFalse($subscription->hasAccess());
    }

    private function makeSubscription(
        SubscriptionStatus $status,
        ?DateTimeImmutable $expiresAt = null,
    ): Subscription {
        $now = new DateTimeImmutable();

        return new Subscription(
            id: 'sub-test-id-00000000000000ab',
            userId: 'user-42',
            store: Store::Google,
            productId: 'com.example.plan',
            plan: 'premium_monthly',
            status: $status,
            purchaseTokenHash: 'hash-abc',
            rawReceiptEncrypted: null,
            originalTransactionId: 'txn-original',
            expiresAt: $expiresAt,
            gracePeriodUntil: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
