<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Subscriptions;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Extension\Subscriptions\Internal\Persistence\DbSubscriptionRepository;
use Pulsar\Extension\Subscriptions\Store;
use Pulsar\Extension\Subscriptions\Subscription;
use Pulsar\Extension\Subscriptions\SubscriptionStatus;

#[CoversClass(DbSubscriptionRepository::class)]
final class DbSubscriptionRepositoryTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function subscriptionRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 'sub-001',
            'user_id' => 'user-1',
            'store' => 'google',
            'product_id' => 'premium_monthly',
            'plan' => 'Premium',
            'status' => 'active',
            'purchase_token_hash' => 'hash-abc',
            'raw_receipt_encrypted' => null,
            'original_transaction_id' => 'txn-001',
            'expires_at' => '2026-02-15T10:00:00+00:00',
            'grace_period_until' => null,
            'created_at' => '2026-01-15T10:00:00+00:00',
            'updated_at' => '2026-01-15T10:00:00+00:00',
        ], $overrides);
    }

    // ── save ─────────────────────────────────────────────────────────

    #[Test]
    public function saveCallsExecuteWithUpsertQuery(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->expects($this->once())->method('execute');

        $subscription = new Subscription(
            id: 'sub-001',
            userId: 'user-1',
            store: Store::Google,
            productId: 'premium_monthly',
            plan: 'Premium',
            status: SubscriptionStatus::Active,
            purchaseTokenHash: 'hash-abc',
            rawReceiptEncrypted: null,
            originalTransactionId: 'txn-001',
            expiresAt: new DateTimeImmutable('2026-02-15T10:00:00+00:00'),
            gracePeriodUntil: null,
            createdAt: new DateTimeImmutable('2026-01-15T10:00:00+00:00'),
            updatedAt: new DateTimeImmutable('2026-01-15T10:00:00+00:00'),
        );

        $repo = new DbSubscriptionRepository($db);
        $repo->save($subscription);
    }

    // ── findByUser ───────────────────────────────────────────────────

    #[Test]
    public function findByUserReturnsSubscriptionWhenFound(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturn(Result::fromArrays([$this->subscriptionRow()]));

        $repo = new DbSubscriptionRepository($db);
        $result = $repo->findByUser('user-1');

        self::assertInstanceOf(Subscription::class, $result);
        self::assertSame('sub-001', $result->id);
        self::assertSame('user-1', $result->userId);
        self::assertSame(Store::Google, $result->store);
        self::assertSame('premium_monthly', $result->productId);
        self::assertSame('Premium', $result->plan);
        self::assertSame(SubscriptionStatus::Active, $result->status);
        self::assertSame('hash-abc', $result->purchaseTokenHash);
        self::assertNull($result->rawReceiptEncrypted);
        self::assertSame('txn-001', $result->originalTransactionId);
        self::assertNotNull($result->expiresAt);
        self::assertNull($result->gracePeriodUntil);
    }

    #[Test]
    public function findByUserReturnsNullWhenNotFound(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturn(Result::fromArrays([]));

        $repo = new DbSubscriptionRepository($db);

        self::assertNull($repo->findByUser('nonexistent'));
    }

    // ── findByPurchaseTokenHash ──────────────────────────────────────

    #[Test]
    public function findByPurchaseTokenHashReturnsSubscriptionWhenFound(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturn(Result::fromArrays([$this->subscriptionRow()]));

        $repo = new DbSubscriptionRepository($db);
        $result = $repo->findByPurchaseTokenHash('hash-abc');

        self::assertInstanceOf(Subscription::class, $result);
        self::assertSame('hash-abc', $result->purchaseTokenHash);
    }

    #[Test]
    public function findByPurchaseTokenHashReturnsNullWhenNotFound(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturn(Result::fromArrays([]));

        $repo = new DbSubscriptionRepository($db);

        self::assertNull($repo->findByPurchaseTokenHash('nonexistent'));
    }

    // ── findByOriginalTransactionId ──────────────────────────────────

    #[Test]
    public function findByOriginalTransactionIdReturnsSubscriptionWhenFound(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturn(Result::fromArrays([$this->subscriptionRow()]));

        $repo = new DbSubscriptionRepository($db);
        $result = $repo->findByOriginalTransactionId('txn-001');

        self::assertInstanceOf(Subscription::class, $result);
        self::assertSame('txn-001', $result->originalTransactionId);
    }

    #[Test]
    public function findByOriginalTransactionIdReturnsNullWhenNotFound(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturn(Result::fromArrays([]));

        $repo = new DbSubscriptionRepository($db);

        self::assertNull($repo->findByOriginalTransactionId('nonexistent'));
    }

    // ── hydration edge cases ─────────────────────────────────────────

    #[Test]
    public function hydratesAllNullableDateFields(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturn(Result::fromArrays([
            $this->subscriptionRow([
                'expires_at' => null,
                'grace_period_until' => null,
            ]),
        ]));

        $repo = new DbSubscriptionRepository($db);
        $result = $repo->findByUser('user-1');

        self::assertInstanceOf(Subscription::class, $result);
        self::assertNull($result->expiresAt);
        self::assertNull($result->gracePeriodUntil);
    }

    #[Test]
    public function hydratesAllPopulatedFields(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturn(Result::fromArrays([
            $this->subscriptionRow([
                'store' => 'apple',
                'status' => 'grace_period',
                'raw_receipt_encrypted' => 'enc-data',
                'grace_period_until' => '2026-03-01T10:00:00+00:00',
            ]),
        ]));

        $repo = new DbSubscriptionRepository($db);
        $result = $repo->findByUser('user-1');

        self::assertInstanceOf(Subscription::class, $result);
        self::assertSame(Store::Apple, $result->store);
        self::assertSame(SubscriptionStatus::GracePeriod, $result->status);
        self::assertSame('enc-data', $result->rawReceiptEncrypted);
        self::assertNotNull($result->gracePeriodUntil);
    }
}
