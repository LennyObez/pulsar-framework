<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Commerce;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Queue\JobContext;
use Pulsar\Queue\QueueableInterface;
use Pulsar\Queue\QueueDriverInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Throwable;

use function json_encode;
use function min;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Queue job that retries a failed webhook with exponential backoff.
 *
 * Delay formula: min(2^retryCount * 60, 3600) seconds.
 * After maxRetries is exceeded, the event is logged and discarded.
 *
 * @psalm-api Instantiated by the queue worker after deserializing job payloads;
 *            entry point invoked by the QueueableInterface contract.
 */
#[Internal(reason: 'Internal webhook retry mechanism; not part of public API')]
final readonly class WebhookRetryJob implements QueueableInterface
{
    private const string QUEUE_NAME = 'cms-webhooks';
    private const int TIMEOUT_SECONDS = 30;
    private const int MAX_BACKOFF_SECONDS = 3600;
    private const int BASE_DELAY_SECONDS = 60;

    public function __construct(
        private string $eventId,
        private string $payload,
        private string $signature,
        private int $retryCount,
        private int $maxRetries,
        private WebhookHandler $webhookHandler,
        private QueueDriverInterface $queueDriver,
        private ?AuditLoggerInterface $auditLogger = null,
    ) {}

    #[Override]
    public function handle(JobContext $context): void
    {
        try {
            $this->webhookHandler->handle($this->payload, $this->signature);
        } catch (Throwable $e) {
            if ($this->retryCount >= $this->maxRetries - 1) {
                $this->auditLogger?->log(
                    AuditEvent::SystemEvent,
                    AuditOutcome::Failure,
                    null,
                    'cms.commerce.webhook.retry_exhausted',
                    sprintf('webhook:%s', $this->eventId),
                    [
                        'event_id' => $this->eventId,
                        'retry_count' => $this->retryCount + 1,
                        'max_retries' => $this->maxRetries,
                        'error' => $e->getMessage(),
                    ],
                );

                return;
            }

            $nextRetryCount = $this->retryCount + 1;
            $delaySeconds = self::calculateDelay($nextRetryCount);

            $jobPayload = json_encode([
                'eventId' => $this->eventId,
                'payload' => $this->payload,
                'signature' => $this->signature,
                'retryCount' => $nextRetryCount,
                'maxRetries' => $this->maxRetries,
            ], JSON_THROW_ON_ERROR);

            $this->queueDriver->push(
                self::QUEUE_NAME,
                self::class,
                $jobPayload,
                $delaySeconds,
            );
        }
    }

    #[Override]
    public function queue(): string
    {
        return self::QUEUE_NAME;
    }

    #[Override]
    public function maxAttempts(): int
    {
        return 1;
    }

    #[Override]
    public function timeout(): int
    {
        return self::TIMEOUT_SECONDS;
    }

    /**
     * Calculate the delay in seconds for a given retry count.
     *
     * Uses exponential backoff: min(2^retryCount * 60, 3600).
     */
    public static function calculateDelay(int $retryCount): int
    {
        return min((1 << $retryCount) * self::BASE_DELAY_SECONDS, self::MAX_BACKOFF_SECONDS);
    }

    /**
     * Deserialize a retry job from its queue payload.
     *
     * @param array{eventId: string, payload: string, signature: string, retryCount: int, maxRetries: int} $data
     */
    public static function fromPayload(
        array $data,
        WebhookHandler $webhookHandler,
        QueueDriverInterface $queueDriver,
        ?AuditLoggerInterface $auditLogger = null,
    ): self {
        return new self(
            eventId: $data['eventId'],
            payload: $data['payload'],
            signature: $data['signature'],
            retryCount: $data['retryCount'],
            maxRetries: $data['maxRetries'],
            webhookHandler: $webhookHandler,
            queueDriver: $queueDriver,
            auditLogger: $auditLogger,
        );
    }
}
