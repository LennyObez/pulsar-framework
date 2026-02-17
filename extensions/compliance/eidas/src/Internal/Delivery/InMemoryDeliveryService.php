<?php

declare(strict_types=1);

namespace Pulsar\Extension\Eidas\Internal\Delivery;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Eidas\Contracts\RegisteredDeliveryServiceInterface;
use Pulsar\Extension\Eidas\Domain\DeliveryReceipt;
use Pulsar\Extension\Eidas\Exception\EidasException;

use function bin2hex;
use function hash;
use function random_bytes;

/**
 * In-memory registered delivery service for development and testing.
 */
#[Internal(reason: 'Use RegisteredDeliveryServiceInterface with a production implementation')]
final class InMemoryDeliveryService implements RegisteredDeliveryServiceInterface
{
    /** @var array<string, DeliveryReceipt> */
    private array $receipts = [];

    #[Override]
    public function send(string $sender, string $recipient, string $content): DeliveryReceipt
    {
        $receiptId = bin2hex(random_bytes(16));
        $messageId = bin2hex(random_bytes(16));
        $now = new DateTimeImmutable();

        $receipt = new DeliveryReceipt(
            receiptId: $receiptId,
            messageId: $messageId,
            sender: $sender,
            recipient: $recipient,
            contentHash: hash('sha256', $content),
            hashAlgorithm: 'sha256',
            sentAt: $now,
            deliveredAt: null,
            delivered: false,
        );

        $this->receipts[$receiptId] = $receipt;

        return $receipt;
    }

    #[Override]
    public function confirmDelivery(string $receiptId): DeliveryReceipt
    {
        $receipt = $this->receipts[$receiptId] ?? null;

        if ($receipt === null) {
            throw EidasException::deliveryFailed($receiptId, 'Receipt not found');
        }

        $confirmed = new DeliveryReceipt(
            receiptId: $receipt->receiptId,
            messageId: $receipt->messageId,
            sender: $receipt->sender,
            recipient: $receipt->recipient,
            contentHash: $receipt->contentHash,
            hashAlgorithm: $receipt->hashAlgorithm,
            sentAt: $receipt->sentAt,
            deliveredAt: new DateTimeImmutable(),
            delivered: true,
        );

        $this->receipts[$receiptId] = $confirmed;

        return $confirmed;
    }

    #[Override]
    public function getReceipt(string $receiptId): ?DeliveryReceipt
    {
        return $this->receipts[$receiptId] ?? null;
    }
}
