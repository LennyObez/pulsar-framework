<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions;

use DateTimeImmutable;
use Pulsar\Api\Api;

use function bin2hex;
use function random_bytes;

/**
 * Represents an inbound webhook notification from a store.
 *
 * The raw payload is encrypted at rest. Signature verification status
 * is recorded at ingestion time. Processing is deferred to queued jobs.
 */
#[Api(since: '1.0.0')]
final readonly class WebhookEvent
{
    /**
     * @param string $id Hex-encoded random identifier
     * @param Store $store Platform that sent the webhook
     * @param string $eventType Store-specific event type (e.g. "SUBSCRIPTION_RENEWED")
     * @param string $payloadEncrypted Encrypted raw webhook payload
     * @param bool $signatureVerified Whether the webhook signature was validated
     * @param DateTimeImmutable|null $processedAt When the event was fully processed
     * @param DateTimeImmutable $createdAt When the webhook was received
     */
    public function __construct(
        public string $id,
        public Store $store,
        public string $eventType,
        public string $payloadEncrypted,
        public bool $signatureVerified,
        public ?DateTimeImmutable $processedAt,
        public DateTimeImmutable $createdAt,
    ) {}

    /**
     * Record a new inbound webhook event.
     */
    public static function create(
        Store $store,
        string $eventType,
        string $payloadEncrypted,
        bool $signatureVerified,
    ): self {
        return new self(
            id: bin2hex(random_bytes(16)),
            store: $store,
            eventType: $eventType,
            payloadEncrypted: $payloadEncrypted,
            signatureVerified: $signatureVerified,
            processedAt: null,
            createdAt: new DateTimeImmutable(),
        );
    }

    /**
     * Mark this event as processed.
     */
    public function markProcessed(): self
    {
        return clone($this, [
            'processedAt' => new DateTimeImmutable(),
        ]);
    }

    public function isProcessed(): bool
    {
        return $this->processedAt !== null;
    }
}
