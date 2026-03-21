<?php

declare(strict_types=1);

namespace Pulsar\Security\DigitalSignature;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Immutable DTO representing a registered electronic delivery receipt.
 *
 * Provides non-repudiation evidence that a message was sent and/or received
 * per eIDAS Articles 43-44 on electronic registered delivery services.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DeliveryReceipt
{
    /**
     * @param string              $messageId   Unique identifier for the delivered message
     * @param string              $sender      Sender identity (email, distinguished name)
     * @param string              $recipient   Recipient identity
     * @param DateTimeImmutable   $sentAt      When the message was sent
     * @param DateTimeImmutable|null $deliveredAt When delivery was confirmed, null if pending
     * @param string              $contentHash Hash of the delivered content for integrity
     * @param string              $hashAlgorithm Algorithm used for content hashing
     * @param bool                $nonRepudiation Whether non-repudiation evidence is available
     */
    public function __construct(
        public string $messageId,
        public string $sender,
        public string $recipient,
        public DateTimeImmutable $sentAt,
        public ?DateTimeImmutable $deliveredAt = null,
        public string $contentHash = '',
        public string $hashAlgorithm = 'sha256',
        public bool $nonRepudiation = false,
    ) {}

    /**
     * Whether the delivery has been confirmed.
     */
    public function isDelivered(): bool
    {
        return $this->deliveredAt !== null;
    }
}
