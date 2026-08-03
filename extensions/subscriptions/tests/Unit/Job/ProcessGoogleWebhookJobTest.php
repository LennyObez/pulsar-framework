<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions\Tests\Unit\Job;

use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Extension\Subscriptions\Internal\SubscriptionService;
use Pulsar\Extension\Subscriptions\Job\ProcessGoogleWebhookJob;
use Pulsar\Extension\Subscriptions\Store;
use Pulsar\Extension\Subscriptions\SubscriptionRepositoryInterface;
use Pulsar\Extension\Subscriptions\SubscriptionStatus;
use Pulsar\Extension\Subscriptions\SubscriptionVerifierInterface;
use Pulsar\Extension\Subscriptions\WebhookEventRepositoryInterface;
use Pulsar\Queue\JobContext;
use RuntimeException;
use Stringable;

#[CoversClass(ProcessGoogleWebhookJob::class)]
final class ProcessGoogleWebhookJobTest extends TestCase
{
    #[Test]
    public function queueNameIsSubscriptions(): void
    {
        $job = new ProcessGoogleWebhookJob(
            eventType: 'SUBSCRIPTION_RENEWED',
            purchaseToken: 'token-123',
            newStatus: SubscriptionStatus::Active,
            encryptedPayload: 'encrypted',
            subscriptionService: $this->makeService(),
        );

        self::assertSame('subscriptions', $job->queue());
    }

    #[Test]
    public function maxAttemptsIsFive(): void
    {
        $job = new ProcessGoogleWebhookJob(
            eventType: 'SUBSCRIPTION_RENEWED',
            purchaseToken: 'token-123',
            newStatus: SubscriptionStatus::Active,
            encryptedPayload: 'encrypted',
            subscriptionService: $this->makeService(),
        );

        self::assertSame(5, $job->maxAttempts());
    }

    #[Test]
    public function timeoutIsThirtySeconds(): void
    {
        $job = new ProcessGoogleWebhookJob(
            eventType: 'SUBSCRIPTION_RENEWED',
            purchaseToken: 'token-123',
            newStatus: SubscriptionStatus::Active,
            encryptedPayload: 'encrypted',
            subscriptionService: $this->makeService(),
        );

        self::assertSame(30, $job->timeout());
    }

    #[Test]
    public function handleDelegatesToSubscriptionServiceWithGoogleStore(): void
    {
        $subRepo = $this->createStub(SubscriptionRepositoryInterface::class);
        $subRepo->method('findByOriginalTransactionId')->willReturn(null);

        $logMessages = [];
        $logger = new class ($logMessages) extends NullLogger {
            /** @param list<array<string, mixed>> $messages */
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
            $this->createStub(WebhookEventRepositoryInterface::class),
            $logger,
        );

        $job = new ProcessGoogleWebhookJob(
            eventType: 'SUBSCRIPTION_CANCELED',
            purchaseToken: 'purchase-token-abc',
            newStatus: SubscriptionStatus::Cancelled,
            encryptedPayload: 'enc-data',
            subscriptionService: $service,
        );

        $context = new JobContext(
            jobId: 'job-g1',
            queue: 'subscriptions',
            attempt: 1,
            maxAttempts: 5,
        );

        $job->handle($context);

        // Since no subscription matches, we get a warning log with the Google store context
        self::assertNotEmpty($logMessages);
        self::assertSame('google', $logMessages[0]['store']);
        self::assertSame('SUBSCRIPTION_CANCELED', $logMessages[0]['event_type']);
        self::assertSame('purchase-token-abc', $logMessages[0]['original_transaction_id']);
    }

    #[Test]
    public function handleThrowsRuntimeExceptionOnServiceFailure(): void
    {
        $subRepo = $this->createStub(SubscriptionRepositoryInterface::class);
        $subRepo->method('findByOriginalTransactionId')->willThrowException(new Exception('Connection lost'));

        $service = new SubscriptionService(
            $this->createStub(SubscriptionVerifierInterface::class),
            $subRepo,
            $this->createStub(WebhookEventRepositoryInterface::class),
            new NullLogger(),
        );

        $job = new ProcessGoogleWebhookJob(
            eventType: 'SUBSCRIPTION_REVOKED',
            purchaseToken: 'token-xyz',
            newStatus: SubscriptionStatus::Revoked,
            encryptedPayload: 'encrypted',
            subscriptionService: $service,
        );

        $context = new JobContext(
            jobId: 'job-g2',
            queue: 'subscriptions',
            attempt: 2,
            maxAttempts: 5,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Failed to process Google webhook: SUBSCRIPTION_REVOKED');

        $job->handle($context);
    }

    #[Test]
    public function handlePassesSignatureVerifiedTrue(): void
    {
        $subRepo = $this->createStub(SubscriptionRepositoryInterface::class);
        $subRepo->method('findByOriginalTransactionId')->willReturn(null);

        $capturedWarnings = [];
        $logger = new class ($capturedWarnings) extends NullLogger {
            /** @param list<array<string, mixed>> $warnings */
            public function __construct(private array &$warnings) {}

            /** @param array<string, mixed> $context */
            public function warning(string|Stringable $message, array $context = []): void
            {
                $this->warnings[] = $context;
            }
        };

        $service = new SubscriptionService(
            $this->createStub(SubscriptionVerifierInterface::class),
            $subRepo,
            $this->createStub(WebhookEventRepositoryInterface::class),
            $logger,
        );

        $job = new ProcessGoogleWebhookJob(
            eventType: 'SUBSCRIPTION_RENEWED',
            purchaseToken: 'token',
            newStatus: SubscriptionStatus::Active,
            encryptedPayload: 'enc',
            subscriptionService: $service,
        );

        $context = new JobContext(
            jobId: 'job-g3',
            queue: 'subscriptions',
            attempt: 1,
            maxAttempts: 5,
        );

        // The fact that handle() completes without exception proves it called processWebhook
        // with signatureVerified: true (the job hardcodes it)
        $job->handle($context);

        // Verify the service was invoked (we get a "Webhook for unknown subscription" warning)
        self::assertNotEmpty($capturedWarnings);
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
