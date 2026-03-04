<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions\Tests\Unit\Job;

use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Extension\Subscriptions\Internal\SubscriptionService;
use Pulsar\Extension\Subscriptions\Job\ProcessAppleWebhookJob;
use Pulsar\Extension\Subscriptions\Store;
use Pulsar\Extension\Subscriptions\SubscriptionRepositoryInterface;
use Pulsar\Extension\Subscriptions\SubscriptionStatus;
use Pulsar\Extension\Subscriptions\SubscriptionVerifierInterface;
use Pulsar\Extension\Subscriptions\WebhookEventRepositoryInterface;
use Pulsar\Queue\JobContext;
use RuntimeException;
use Stringable;

#[CoversClass(ProcessAppleWebhookJob::class)]
final class ProcessAppleWebhookJobTest extends TestCase
{
    #[Test]
    public function queueNameIsSubscriptions(): void
    {
        $job = new ProcessAppleWebhookJob(
            notificationType: 'DID_RENEW',
            originalTransactionId: 'txn-123',
            newStatus: SubscriptionStatus::Active,
            encryptedPayload: 'encrypted',
            subscriptionService: $this->makeService(),
        );

        self::assertSame('subscriptions', $job->queue());
    }

    #[Test]
    public function maxAttemptsIsFive(): void
    {
        $job = new ProcessAppleWebhookJob(
            notificationType: 'DID_RENEW',
            originalTransactionId: 'txn-123',
            newStatus: SubscriptionStatus::Active,
            encryptedPayload: 'encrypted',
            subscriptionService: $this->makeService(),
        );

        self::assertSame(5, $job->maxAttempts());
    }

    #[Test]
    public function timeoutIsThirtySeconds(): void
    {
        $job = new ProcessAppleWebhookJob(
            notificationType: 'DID_RENEW',
            originalTransactionId: 'txn-123',
            newStatus: SubscriptionStatus::Active,
            encryptedPayload: 'encrypted',
            subscriptionService: $this->makeService(),
        );

        self::assertSame(30, $job->timeout());
    }

    #[Test]
    public function handleDelegatesToSubscriptionServiceWithAppleStore(): void
    {
        $capturedStore = null;
        $capturedEventType = null;
        $capturedTxnId = null;
        $capturedStatus = null;

        $webhookRepo = $this->createStub(WebhookEventRepositoryInterface::class);
        $subRepo = $this->createStub(SubscriptionRepositoryInterface::class);
        $subRepo->method('findByOriginalTransactionId')->willReturn(null);

        $logMessages = [];
        $logger = new class ($logMessages) extends NullLogger {
            /** @param list<string> $messages */
            public function __construct(private array &$messages) {}

            /** @param array<string, mixed> $context */
            public function warning(string|Stringable $message, array $context = []): void
            {
                $this->messages[] = [
                    'store' => $context['store'] ?? null,
                    'event_type' => $context['event_type'] ?? null,
                    'original_transaction_id' => $context['original_transaction_id'] ?? null,
                ];
            }
        };

        $service = new SubscriptionService(
            $this->createStub(SubscriptionVerifierInterface::class),
            $subRepo,
            $webhookRepo,
            $logger,
        );

        $job = new ProcessAppleWebhookJob(
            notificationType: 'EXPIRED',
            originalTransactionId: 'txn-456',
            newStatus: SubscriptionStatus::Expired,
            encryptedPayload: 'enc-payload',
            subscriptionService: $service,
        );

        $context = new JobContext(
            jobId: 'job-1',
            queue: 'subscriptions',
            attempt: 1,
            maxAttempts: 5,
        );

        $job->handle($context);

        // Since no subscription matches, it logs a warning with Apple store context
        self::assertNotEmpty($logMessages);
        self::assertSame('apple', $logMessages[0]['store']);
        self::assertSame('EXPIRED', $logMessages[0]['event_type']);
        self::assertSame('txn-456', $logMessages[0]['original_transaction_id']);
    }

    #[Test]
    public function handleThrowsRuntimeExceptionOnServiceFailure(): void
    {
        $subRepo = $this->createStub(SubscriptionRepositoryInterface::class);
        $subRepo->method('findByOriginalTransactionId')->willThrowException(new Exception('DB failure'));

        $service = new SubscriptionService(
            $this->createStub(SubscriptionVerifierInterface::class),
            $subRepo,
            $this->createStub(WebhookEventRepositoryInterface::class),
            new NullLogger(),
        );

        $job = new ProcessAppleWebhookJob(
            notificationType: 'DID_FAIL_TO_RENEW',
            originalTransactionId: 'txn-789',
            newStatus: SubscriptionStatus::GracePeriod,
            encryptedPayload: 'encrypted',
            subscriptionService: $service,
        );

        $context = new JobContext(
            jobId: 'job-2',
            queue: 'subscriptions',
            attempt: 1,
            maxAttempts: 5,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to process Apple webhook: DID_FAIL_TO_RENEW');

        $job->handle($context);
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
}
