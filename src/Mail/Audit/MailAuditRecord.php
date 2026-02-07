<?php

declare(strict_types=1);

namespace Pulsar\Mail\Audit;

use Pulsar\Api\Api;

/**
 * Immutable audit record for a mail message.
 *
 * Contains only metadata and HMAC hashes — never raw content.
 * Recipient identity is pseudonymized via HMAC.
 */
#[Api(since: '1.0.0')]
final readonly class MailAuditRecord
{
    /**
     * @param list<string> $attachmentHashes HMAC hashes of attachment contents
     */
    public function __construct(
        public string $messageId,
        public ?string $templateId,
        public string $recipientId,
        public string $channel,
        public int $sentAt,
        public ?int $deliveredAt,
        public DeliveryStatus $deliveryStatus,
        public ?string $correlationId,
        public ?string $bodyHash,
        public array $attachmentHashes,
    ) {}

    /**
     * Convert to metadata array suitable for audit logging.
     *
     * @return array<string, mixed>
     */
    public function toMetadata(): array
    {
        return [
            'message_id' => $this->messageId,
            'template_id' => $this->templateId,
            'recipient_id' => $this->recipientId,
            'channel' => $this->channel,
            'sent_at' => $this->sentAt,
            'delivered_at' => $this->deliveredAt,
            'delivery_status' => $this->deliveryStatus->value,
            'correlation_id' => $this->correlationId,
            'body_hash' => $this->bodyHash,
            'attachment_hashes' => $this->attachmentHashes,
        ];
    }
}
