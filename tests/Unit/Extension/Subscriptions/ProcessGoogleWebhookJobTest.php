<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Subscriptions;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Extension\Subscriptions\Internal\SubscriptionService;
use Pulsar\Extension\Subscriptions\Job\ProcessGoogleWebhookJob;
use Pulsar\Extension\Subscriptions\Store;
use Pulsar\Extension\Subscriptions\Subscription;
use Pulsar\Extension\Subscriptions\SubscriptionRepositoryInterface;
use Pulsar\Extension\Subscriptions\SubscriptionStatus;
use Pulsar\Extension\Subscriptions\SubscriptionVerifierInterface;
use Pulsar\Extension\Subscriptions\WebhookEventRepositoryInterface;
use Pulsar\Queue\JobContext;
use RuntimeException;

final class ProcessGoogleWebhookJobTest extends TestCase
{
    private function makeJobContext(): JobContext
    {
        return new JobContext(
            jobId: 'job-001',
            queue: 'subscriptions',
            attempt: 1,
            maxAttempts: 5,
        );
    }

    private function makeSubscription(): Subscription
    {
        $now = new DateTimeImmutable();

        return new Subscription(
            id: 'sub-001',
            userId: 'user-1',
            store: Store::Google,
            productId: 'premium_monthly',
            plan: 'Premium Monthly',
            status: SubscriptionStatus::Active,
            purchaseTokenHash: 'hash-abc',
            rawReceiptEncrypted: null,
            originalTransactionId: 'google-purchase-token',
            expiresAt: new DateTimeImmutable('+30 days'),
            gracePeriodUntil: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    #[Test]
    public function handleDelegatesProcessingToSubscriptionService(): void
    {
        $sub = $this->makeSubscription();

        /** @var SubscriptionRepositoryInterface&MockObject $subRepo */
        $subRepo = $this->createMock(SubscriptionRepositoryInterface::class);
        $subRepo->method('findByOriginalTransactionId')->willReturn($sub);
        $subRepo->expects(self::once())->method('save');

        /** @var WebhookEventRepositoryInterface&MockObject $webhookRepo */
        $webhookRepo = $this->createMock(WebhookEventRepositoryInterface::class);
        $webhookRepo->expects(self::exactly(2))->method('save');

        $service = new SubscriptionService(
            $this->createStub(SubscriptionVerifierInterface::class),
            $subRepo,
            $webhookRepo,
            new NullLogger(),
        );

        $job = new ProcessGoogleWebhookJob(
            eventType: 'SUBSCRIPTION_CANCELED',
            purchaseToken: 'google-purchase-token',
            newStatus: SubscriptionStatus::Cancelled,
            encryptedPayload: 'encrypted-data',
            subscriptionService: $service,
        );

        $job->handle($this->makeJobContext());
    }

    #[Test]
    public function handleRethrowsAsRuntimeExceptionOnFailure(): void
    {
        /** @var WebhookEventRepositoryInterface&Stub $webhookRepo */
        $webhookRepo = $this->createStub(WebhookEventRepositoryInterface::class);
        $webhookRepo->method('save')
            ->willThrowException(new RuntimeException('DB error'));

        $service = new SubscriptionService(
            $this->createStub(SubscriptionVerifierInterface::class),
            $this->createStub(SubscriptionRepositoryInterface::class),
            $webhookRepo,
            new NullLogger(),
        );

        $job = new ProcessGoogleWebhookJob(
            eventType: 'SUBSCRIPTION_RENEWED',
            purchaseToken: 'token-xyz',
            newStatus: SubscriptionStatus::Active,
            encryptedPayload: 'payload',
            subscriptionService: $service,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to process Google webhook');

        $job->handle($this->makeJobContext());
    }

    private function makeService(): SubscriptionService
    {
        return new SubscriptionService(
            $this->createStub(SubscriptionVerifierInterface::class),
            $this->createStub(SubscriptionRepositoryInterface::class),
            $this->createStub(WebhookEventRepositoryInterface::class),
            new NullLogger(),
        );
    }

    #[Test]
    public function queueReturnsSubscriptionsQueue(): void
    {
        $job = new ProcessGoogleWebhookJob(
            eventType: 'test',
            purchaseToken: 'token',
            newStatus: SubscriptionStatus::Active,
            encryptedPayload: '',
            subscriptionService: $this->makeService(),
        );

        self::assertSame('subscriptions', $job->queue());
    }

    #[Test]
    public function maxAttemptsReturnsFive(): void
    {
        $job = new ProcessGoogleWebhookJob(
            eventType: 'test',
            purchaseToken: 'token',
            newStatus: SubscriptionStatus::Active,
            encryptedPayload: '',
            subscriptionService: $this->makeService(),
        );

        self::assertSame(5, $job->maxAttempts());
    }

    #[Test]
    public function timeoutReturnsThirtySeconds(): void
    {
        $job = new ProcessGoogleWebhookJob(
            eventType: 'test',
            purchaseToken: 'token',
            newStatus: SubscriptionStatus::Active,
            encryptedPayload: '',
            subscriptionService: $this->makeService(),
        );

        self::assertSame(30, $job->timeout());
    }
}
