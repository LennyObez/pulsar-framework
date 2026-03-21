<?php

declare(strict_types=1);

namespace Pulsar\Extension\Eidas\Domain;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Delivery receipt for registered electronic delivery per Art. 43-44.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DeliveryReceipt
{
    public function __construct(
        public string $receiptId,
        public string $messageId,
        public string $sender,
        public string $recipient,
        public string $contentHash,
        public string $hashAlgorithm,
        public DateTimeImmutable $sentAt,
        public ?DateTimeImmutable $deliveredAt,
        public bool $delivered,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return [
            'receipt_id' => $this->receiptId,
            'message_id' => $this->messageId,
            'sender' => $this->sender,
            'recipient' => $this->recipient,
            'content_hash' => $this->contentHash,
            'hash_algorithm' => $this->hashAlgorithm,
            'sent_at' => $this->sentAt->format('Y-m-d\TH:i:s.uP'),
            'delivered_at' => $this->deliveredAt?->format('Y-m-d\TH:i:s.uP'),
            'delivered' => $this->delivered,
        ];
    }
}
