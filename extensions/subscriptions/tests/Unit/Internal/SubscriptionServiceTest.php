<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions\Tests\Unit\Internal;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
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
    #[Test]
    public function verifyAndSaveCreatesNewSubscriptionWhenNoneExists(): void
    {
        $expires = new DateTimeImmutable('+30 days');
        $verificationResult = new VerificationResult(
            isValid: true,
            expiresAt: $expires,
            gracePeriodUntil: null,
            productId: 'com.example.premium',
            autoRenewing: true,
        );

        $verifier = $this->createStub(SubscriptionVerifierInterface::class);
        $verifier->method('verify')->willReturn($verificationResult);

        $savedSubscription = null;
        $subscriptionRepo = $this->createStub(SubscriptionRepositoryInterface::class);
        $subscriptionRepo->method('findByPurchaseTokenHash')->willReturn(null);
        $subscriptionRepo->method('save')->willReturnCallback(
            function (Subscription $sub) use (&$savedSubscription): void {
                $savedSubscription = $sub;
            },
        );

        $webhookRepo = $this->createStub(WebhookEventRepositoryInterface::class);
        $logger = $this->createStub(LoggerInterface::class);

        $service = new SubscriptionService($verifier, $subscriptionRepo, $webhookRepo, $logger);

        $result = $service->verifyAndSave('user-1', Store::Google, 'raw-token', 'premium_monthly');

        self::assertSame('user-1', $result->userId);
        self::assertSame(Store::Google, $result->store);
        self::assertSame('com.example.premium', $result->productId);
        self::assertSame('premium_monthly', $result->plan);
        self::assertSame(SubscriptionStatus::Active, $result->status);
        self::assertSame($expires, $result->expiresAt);
        self::assertNotNull($savedSubscription);
    }

    #[Test]
    public function verifyAndSaveUpdatesExistingSubscriptionWhenTokenHashMatches(): void
    {
        $expires = new DateTimeImmutable('+30 days');
        $verificationResult = new VerificationResult(
            isValid: true,
            expiresAt: $expires,
            gracePeriodUntil: null,
            productId: 'com.example.premium',
            autoRenewing: true,
        );

        $verifier = $this->createStub(SubscriptionVerifierInterface::class);
        $verifier->method('verify')->willReturn($verificationResult);

        $existing = new Subscription(
            id: 'existing-id-0000000000000ab',
            userId: 'user-1',
            store: Store::Google,
            productId: 'com.example.premium',
            plan: 'premium_monthly',
            status: SubscriptionStatus::Expired,
            purchaseTokenHash: hash('sha256', 'raw-token'),
            rawReceiptEncrypted: null,
            originalTransactionId: 'raw-token',
            expiresAt: new DateTimeImmutable('-1 day'),
            gracePeriodUntil: null,
            createdAt: new DateTimeImmutable('-90 days'),
            updatedAt: new DateTimeImmutable('-1 day'),
        );

        $subscriptionRepo = $this->createStub(SubscriptionRepositoryInterface::class);
        $subscriptionRepo->method('findByPurchaseTokenHash')->willReturn($existing);

        $webhookRepo = $this->createStub(WebhookEventRepositoryInterface::class);
        $logger = $this->createStub(LoggerInterface::class);

        $service = new SubscriptionService($verifier, $subscriptionRepo, $webhookRepo, $logger);

        $result = $service->verifyAndSave('user-1', Store::Google, 'raw-token', 'premium_monthly');

        self::assertSame('existing-id-0000000000000ab', $result->id);
        self::assertSame(SubscriptionStatus::Active, $result->status);
        self::assertSame($expires, $result->expiresAt);
    }

    #[Test]
    public function verifyAndSaveThrowsWhenVerificationFails(): void
    {
        $verifier = $this->createStub(SubscriptionVerifierInterface::class);
        $verifier->method('verify')->willReturn(VerificationResult::invalid());

        $subscriptionRepo = $this->createStub(SubscriptionRepositoryInterface::class);
        $webhookRepo = $this->createStub(WebhookEventRepositoryInterface::class);
        $logger = $this->createStub(LoggerInterface::class);

        $service = new SubscriptionService($verifier, $subscriptionRepo, $webhookRepo, $logger);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Purchase verification failed');

        $service->verifyAndSave('user-1', Store::Apple, 'bad-token', 'plan');
    }

    #[Test]
    public function getStatusDelegatesToRepository(): void
    {
        $now = new DateTimeImmutable();
        $expected = new Subscription(
            id: 'sub-id-000000000000000ab',
            userId: 'user-42',
            store: Store::Apple,
            productId: 'com.example.plan',
            plan: 'yearly',
            status: SubscriptionStatus::Active,
            purchaseTokenHash: 'hash',
            rawReceiptEncrypted: null,
            originalTransactionId: 'txn',
            expiresAt: new DateTimeImmutable('+365 days'),
            gracePeriodUntil: null,
            createdAt: $now,
            updatedAt: $now,
        );

        $subscriptionRepo = $this->createStub(SubscriptionRepositoryInterface::class);
        $subscriptionRepo->method('findByUser')->willReturn($expected);

        $verifier = $this->createStub(SubscriptionVerifierInterface::class);
        $webhookRepo = $this->createStub(WebhookEventRepositoryInterface::class);
        $logger = $this->createStub(LoggerInterface::class);

        $service = new SubscriptionService($verifier, $subscriptionRepo, $webhookRepo, $logger);

        $result = $service->getStatus('user-42');

        self::assertSame($expected, $result);
    }

    #[Test]
    public function getStatusReturnsNullWhenNoSubscriptionExists(): void
    {
        $subscriptionRepo = $this->createStub(SubscriptionRepositoryInterface::class);
        $subscriptionRepo->method('findByUser')->willReturn(null);

        $verifier = $this->createStub(SubscriptionVerifierInterface::class);
        $webhookRepo = $this->createStub(WebhookEventRepositoryInterface::class);
        $logger = $this->createStub(LoggerInterface::class);

        $service = new SubscriptionService($verifier, $subscriptionRepo, $webhookRepo, $logger);

        self::assertNull($service->getStatus('nonexistent-user'));
    }

    #[Test]
    public function restoreDelegatesToVerifyAndSave(): void
    {
        $verificationResult = new VerificationResult(
            isValid: true,
            expiresAt: new DateTimeImmutable('+30 days'),
            gracePeriodUntil: null,
            productId: 'com.example.premium',
            autoRenewing: true,
        );

        $verifier = $this->createStub(SubscriptionVerifierInterface::class);
        $verifier->method('verify')->willReturn($verificationResult);

        $subscriptionRepo = $this->createStub(SubscriptionRepositoryInterface::class);
        $subscriptionRepo->method('findByPurchaseTokenHash')->willReturn(null);

        $webhookRepo = $this->createStub(WebhookEventRepositoryInterface::class);
        $logger = $this->createStub(LoggerInterface::class);

        $service = new SubscriptionService($verifier, $subscriptionRepo, $webhookRepo, $logger);

        $result = $service->restore('user-1', Store::Google, 'token', 'plan');

        self::assertSame('user-1', $result->userId);
        self::assertSame(SubscriptionStatus::Active, $result->status);
    }

    #[Test]
    public function processWebhookUpdatesSubscriptionAndMarksEventProcessed(): void
    {
        $now = new DateTimeImmutable();
        $existing = new Subscription(
            id: 'sub-id-000000000000000ab',
            userId: 'user-5',
            store: Store::Google,
            productId: 'com.example.plan',
            plan: 'monthly',
            status: SubscriptionStatus::Active,
            purchaseTokenHash: 'hash-abc',
            rawReceiptEncrypted: null,
            originalTransactionId: 'txn-original',
            expiresAt: new DateTimeImmutable('+30 days'),
            gracePeriodUntil: null,
            createdAt: $now,
            updatedAt: $now,
        );

        $subscriptionRepo = $this->createStub(SubscriptionRepositoryInterface::class);
        $subscriptionRepo->method('findByOriginalTransactionId')->willReturn($existing);

        $savedItems = [];
        $webhookRepo = $this->createStub(WebhookEventRepositoryInterface::class);
        $webhookRepo->method('save')->willReturnCallback(
            function ($item) use (&$savedItems): void {
                $savedItems[] = $item;
            },
        );

        $verifier = $this->createStub(SubscriptionVerifierInterface::class);
        $logger = $this->createStub(LoggerInterface::class);

        $service = new SubscriptionService($verifier, $subscriptionRepo, $webhookRepo, $logger);

        $service->processWebhook(
            store: Store::Google,
            eventType: 'SUBSCRIPTION_CANCELED',
            originalTransactionId: 'txn-original',
            newStatus: SubscriptionStatus::Cancelled,
            encryptedPayload: 'encrypted',
            signatureVerified: true,
        );

        // Webhook repo save is called twice: once for initial event, once for markProcessed
        self::assertCount(2, $savedItems);
    }

    #[Test]
    public function processWebhookLogsWarningForUnknownTransactionId(): void
    {
        $subscriptionRepo = $this->createStub(SubscriptionRepositoryInterface::class);
        $subscriptionRepo->method('findByOriginalTransactionId')->willReturn(null);

        $webhookRepo = $this->createStub(WebhookEventRepositoryInterface::class);
        $verifier = $this->createStub(SubscriptionVerifierInterface::class);

        $loggedWarnings = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(
            function (string $message) use (&$loggedWarnings): void {
                $loggedWarnings[] = $message;
            },
        );

        $service = new SubscriptionService($verifier, $subscriptionRepo, $webhookRepo, $logger);

        $service->processWebhook(
            store: Store::Apple,
            eventType: 'DID_RENEW',
            originalTransactionId: 'unknown-txn',
            newStatus: SubscriptionStatus::Active,
            encryptedPayload: 'encrypted',
            signatureVerified: true,
        );

        self::assertContains('Webhook for unknown subscription', $loggedWarnings);
    }
}
