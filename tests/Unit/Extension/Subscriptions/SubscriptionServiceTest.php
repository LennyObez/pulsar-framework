<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Subscriptions;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Extension\Subscriptions\Internal\SubscriptionService;
use Pulsar\Extension\Subscriptions\Store;
use Pulsar\Extension\Subscriptions\Subscription;
use Pulsar\Extension\Subscriptions\SubscriptionRepositoryInterface;
use Pulsar\Extension\Subscriptions\SubscriptionStatus;
use Pulsar\Extension\Subscriptions\SubscriptionVerifierInterface;
use Pulsar\Extension\Subscriptions\VerificationResult;
use Pulsar\Extension\Subscriptions\WebhookEventRepositoryInterface;
use RuntimeException;

#[CoversClass(SubscriptionService::class)]
final class SubscriptionServiceTest extends TestCase
{
    private SubscriptionVerifierInterface&Stub $verifier;
    private SubscriptionRepositoryInterface&Stub $subscriptionRepo;
    private WebhookEventRepositoryInterface&Stub $webhookRepo;
    private SubscriptionService $service;

    protected function setUp(): void
    {
        $this->verifier = $this->createStub(SubscriptionVerifierInterface::class);
        $this->subscriptionRepo = $this->createStub(SubscriptionRepositoryInterface::class);
        $this->webhookRepo = $this->createStub(WebhookEventRepositoryInterface::class);

        $this->service = new SubscriptionService(
            $this->verifier,
            $this->subscriptionRepo,
            $this->webhookRepo,
            new NullLogger(),
        );
    }

    #[Test]
    public function verifyAndSaveCreatesSubscriptionForValidToken(): void
    {
        $expiresAt = new DateTimeImmutable('+30 days');
        $result = new VerificationResult(
            isValid: true,
            expiresAt: $expiresAt,
            gracePeriodUntil: null,
            productId: 'premium_monthly',
            autoRenewing: true,
        );

        $this->verifier->method('verify')->willReturn($result);
        $this->subscriptionRepo->method('findByPurchaseTokenHash')->willReturn(null);

        $subscription = $this->service->verifyAndSave(
            'user-001',
            Store::Google,
            'purchase-token-123',
            'Premium Monthly',
        );

        self::assertSame('user-001', $subscription->userId);
        self::assertSame(Store::Google, $subscription->store);
        self::assertSame('premium_monthly', $subscription->productId);
        self::assertSame('Premium Monthly', $subscription->plan);
        self::assertSame(SubscriptionStatus::Active, $subscription->status);
    }

    #[Test]
    public function verifyAndSaveThrowsForInvalidToken(): void
    {
        $result = VerificationResult::invalid();
        $this->verifier->method('verify')->willReturn($result);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Purchase verification failed');

        $this->service->verifyAndSave('user-001', Store::Apple, 'bad-token', 'plan');
    }

    #[Test]
    public function verifyAndSaveUpdatesExistingSubscription(): void
    {
        $expiresAt = new DateTimeImmutable('+30 days');
        $result = new VerificationResult(
            isValid: true,
            expiresAt: $expiresAt,
            gracePeriodUntil: null,
            productId: 'premium_monthly',
            autoRenewing: true,
        );

        $existing = self::makeSubscription('sub-001', 'user-001', SubscriptionStatus::Expired);

        $this->verifier->method('verify')->willReturn($result);
        $this->subscriptionRepo->method('findByPurchaseTokenHash')->willReturn($existing);

        $subscription = $this->service->verifyAndSave(
            'user-001',
            Store::Google,
            'same-token',
            'Premium Monthly',
        );

        self::assertSame(SubscriptionStatus::Active, $subscription->status);
        self::assertSame($expiresAt, $subscription->expiresAt);
    }

    #[Test]
    public function getStatusReturnsSubscriptionForExistingUser(): void
    {
        $sub = self::makeSubscription('sub-001', 'user-001', SubscriptionStatus::Active);
        $this->subscriptionRepo->method('findByUser')->willReturn($sub);

        $result = $this->service->getStatus('user-001');

        self::assertNotNull($result);
        self::assertSame('sub-001', $result->id);
        self::assertSame(SubscriptionStatus::Active, $result->status);
    }

    #[Test]
    public function getStatusReturnsNullForNonSubscriber(): void
    {
        $this->subscriptionRepo->method('findByUser')->willReturn(null);

        self::assertNull($this->service->getStatus('user-no-sub'));
    }

    #[Test]
    public function restoreReVerifiesPurchaseToken(): void
    {
        $expiresAt = new DateTimeImmutable('+30 days');
        $result = new VerificationResult(
            isValid: true,
            expiresAt: $expiresAt,
            gracePeriodUntil: null,
            productId: 'premium_annual',
            autoRenewing: true,
        );

        $this->verifier->method('verify')->willReturn($result);
        $this->subscriptionRepo->method('findByPurchaseTokenHash')->willReturn(null);

        $subscription = $this->service->restore(
            'user-001',
            Store::Apple,
            'restore-token',
            'Premium Annual',
        );

        self::assertSame('user-001', $subscription->userId);
        self::assertSame(Store::Apple, $subscription->store);
        self::assertSame(SubscriptionStatus::Active, $subscription->status);
    }

    #[Test]
    public function processWebhookUpdatesSubscriptionStatus(): void
    {
        $sub = self::makeSubscription('sub-001', 'user-001', SubscriptionStatus::Active);

        /** @var SubscriptionRepositoryInterface&MockObject $subRepo */
        $subRepo = $this->createMock(SubscriptionRepositoryInterface::class);
        $subRepo->method('findByOriginalTransactionId')->willReturn($sub);
        $subRepo->expects(self::once())->method('save');

        /** @var WebhookEventRepositoryInterface&MockObject $webhookRepo */
        $webhookRepo = $this->createMock(WebhookEventRepositoryInterface::class);
        $webhookRepo->expects(self::exactly(2))->method('save');

        $service = new SubscriptionService(
            $this->verifier,
            $subRepo,
            $webhookRepo,
            new NullLogger(),
        );

        $service->processWebhook(
            store: Store::Google,
            eventType: 'SUBSCRIPTION_CANCELED',
            originalTransactionId: 'txn-001',
            newStatus: SubscriptionStatus::Cancelled,
            encryptedPayload: 'encrypted-data',
            signatureVerified: true,
        );
    }

    #[Test]
    public function processWebhookHandlesUnknownSubscription(): void
    {
        /** @var SubscriptionRepositoryInterface&MockObject $subRepo */
        $subRepo = $this->createMock(SubscriptionRepositoryInterface::class);
        $subRepo->method('findByOriginalTransactionId')->willReturn(null);
        $subRepo->expects(self::never())->method('save');

        $service = new SubscriptionService(
            $this->verifier,
            $subRepo,
            $this->webhookRepo,
            new NullLogger(),
        );

        $service->processWebhook(
            store: Store::Apple,
            eventType: 'DID_RENEW',
            originalTransactionId: 'unknown-txn',
            newStatus: SubscriptionStatus::Active,
            encryptedPayload: 'encrypted-data',
            signatureVerified: true,
        );
    }

    private static function makeSubscription(
        string $id,
        string $userId,
        SubscriptionStatus $status,
    ): Subscription {
        $now = new DateTimeImmutable();

        return new Subscription(
            id: $id,
            userId: $userId,
            store: Store::Google,
            productId: 'premium_monthly',
            plan: 'Premium Monthly',
            status: $status,
            purchaseTokenHash: hash('sha256', 'token'),
            rawReceiptEncrypted: null,
            originalTransactionId: 'txn-001',
            expiresAt: new DateTimeImmutable('+30 days'),
            gracePeriodUntil: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
