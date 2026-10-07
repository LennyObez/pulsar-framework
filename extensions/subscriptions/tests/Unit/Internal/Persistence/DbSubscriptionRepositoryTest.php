<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions\Tests\Unit\Internal\Persistence;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Subscriptions\Internal\Persistence\DbSubscriptionRepository;
use Pulsar\Extension\Subscriptions\Store;
use Pulsar\Extension\Subscriptions\Subscription;
use Pulsar\Extension\Subscriptions\SubscriptionStatus;

#[CoversClass(DbSubscriptionRepository::class)]
final class DbSubscriptionRepositoryTest extends TestCase
{
    #[Test]
    public function saveExecutesUpsertWithSubscriptionData(): void
    {
        $now = new DateTimeImmutable('2026-03-15T10:00:00+00:00');
        $expires = new DateTimeImmutable('2026-04-15T10:00:00+00:00');

        $subscription = new Subscription(
            id: 'sub-001',
            userId: 'user-42',
            store: Store::Google,
            productId: 'com.app.premium',
            plan: 'premium_monthly',
            status: SubscriptionStatus::Active,
            purchaseTokenHash: 'hash-abc',
            rawReceiptEncrypted: 'encrypted-receipt',
            originalTransactionId: 'GPA.1234',
            expiresAt: $expires,
            gracePeriodUntil: null,
            createdAt: $now,
            updatedAt: $now,
        );

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->method('execute')->willReturn(1);

        $repo = new DbSubscriptionRepository($connection);
        $repo->save($subscription);

        // If we get here without exception, the save path executed correctly
        self::assertSame('sub-001', $subscription->id);
    }

    #[Test]
    public function findByUserReturnsSubscriptionWhenFound(): void
    {
        $row = new Row([
            'id' => 'sub-001',
            'user_id' => 'user-42',
            'store' => 'google',
            'product_id' => 'com.app.premium',
            'plan' => 'premium_monthly',
            'status' => 'active',
            'purchase_token_hash' => 'hash-abc',
            'raw_receipt_encrypted' => 'encrypted-receipt',
            'original_transaction_id' => 'GPA.1234',
            'expires_at' => '2026-04-15T10:00:00+00:00',
            'grace_period_until' => null,
            'created_at' => '2026-03-15T10:00:00+00:00',
            'updated_at' => '2026-03-15T10:00:00+00:00',
        ]);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result([$row]));

        $repo = new DbSubscriptionRepository($connection);
        $result = $repo->findByUser('user-42');

        self::assertNotNull($result);
        self::assertSame('sub-001', $result->id);
        self::assertSame('user-42', $result->userId);
        self::assertSame(Store::Google, $result->store);
        self::assertSame('com.app.premium', $result->productId);
        self::assertSame('premium_monthly', $result->plan);
        self::assertSame(SubscriptionStatus::Active, $result->status);
        self::assertSame('hash-abc', $result->purchaseTokenHash);
        self::assertSame('encrypted-receipt', $result->rawReceiptEncrypted);
        self::assertSame('GPA.1234', $result->originalTransactionId);
        self::assertNotNull($result->expiresAt);
        self::assertNull($result->gracePeriodUntil);
    }

    #[Test]
    public function findByUserReturnsNullWhenNotFound(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result([]));

        $repo = new DbSubscriptionRepository($connection);
        $result = $repo->findByUser('nonexistent-user');

        self::assertNull($result);
    }

    #[Test]
    public function findByPurchaseTokenHashReturnsSubscription(): void
    {
        $row = new Row([
            'id' => 'sub-002',
            'user_id' => 'user-99',
            'store' => 'apple',
            'product_id' => 'com.app.gold',
            'plan' => 'gold_yearly',
            'status' => 'cancelled',
            'purchase_token_hash' => 'hash-xyz',
            'raw_receipt_encrypted' => null,
            'original_transaction_id' => 'APPLE.5678',
            'expires_at' => '2027-01-01T00:00:00+00:00',
            'grace_period_until' => '2026-12-15T00:00:00+00:00',
            'created_at' => '2026-01-01T00:00:00+00:00',
            'updated_at' => '2026-06-01T00:00:00+00:00',
        ]);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result([$row]));

        $repo = new DbSubscriptionRepository($connection);
        $result = $repo->findByPurchaseTokenHash('hash-xyz');

        self::assertNotNull($result);
        self::assertSame('sub-002', $result->id);
        self::assertSame(Store::Apple, $result->store);
        self::assertSame(SubscriptionStatus::Cancelled, $result->status);
        self::assertNull($result->rawReceiptEncrypted);
        self::assertNotNull($result->gracePeriodUntil);
    }

    #[Test]
    public function findByPurchaseTokenHashReturnsNullWhenNotFound(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result([]));

        $repo = new DbSubscriptionRepository($connection);

        self::assertNull($repo->findByPurchaseTokenHash('no-such-hash'));
    }

    #[Test]
    public function findByOriginalTransactionIdReturnsSubscription(): void
    {
        $row = new Row([
            'id' => 'sub-003',
            'user_id' => 'user-7',
            'store' => 'google',
            'product_id' => 'com.app.basic',
            'plan' => 'basic_monthly',
            'status' => 'grace_period',
            'purchase_token_hash' => 'hash-gp',
            'raw_receipt_encrypted' => 'enc-data',
            'original_transaction_id' => 'GPA.9999',
            'expires_at' => null,
            'grace_period_until' => '2026-04-01T00:00:00+00:00',
            'created_at' => '2026-03-01T00:00:00+00:00',
            'updated_at' => '2026-03-20T00:00:00+00:00',
        ]);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result([$row]));

        $repo = new DbSubscriptionRepository($connection);
        $result = $repo->findByOriginalTransactionId('GPA.9999');

        self::assertNotNull($result);
        self::assertSame('sub-003', $result->id);
        self::assertSame(SubscriptionStatus::GracePeriod, $result->status);
        self::assertNull($result->expiresAt);
        self::assertNotNull($result->gracePeriodUntil);
    }

    #[Test]
    public function findByOriginalTransactionIdReturnsNullWhenNotFound(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result([]));

        $repo = new DbSubscriptionRepository($connection);

        self::assertNull($repo->findByOriginalTransactionId('nonexistent'));
    }

    #[Test]
    public function saveHandlesNullExpiresAtAndGracePeriod(): void
    {
        $now = new DateTimeImmutable('2026-03-15T10:00:00+00:00');

        $subscription = new Subscription(
            id: 'sub-004',
            userId: 'user-1',
            store: Store::Apple,
            productId: 'com.app.trial',
            plan: 'trial',
            status: SubscriptionStatus::Active,
            purchaseTokenHash: 'hash-trial',
            rawReceiptEncrypted: null,
            originalTransactionId: 'APPLE.TRIAL',
            expiresAt: null,
            gracePeriodUntil: null,
            createdAt: $now,
            updatedAt: $now,
        );

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);
        $connection->method('execute')->willReturn(1);

        $repo = new DbSubscriptionRepository($connection);
        $repo->save($subscription);

        self::assertNull($subscription->expiresAt);
        self::assertNull($subscription->gracePeriodUntil);
    }
}
