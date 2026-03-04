<?php

declare(strict_types=1);

namespace Pulsar\Security\DigitalSignature;

use Pulsar\Api\Api;

/**
 * Service for electronic registered delivery per eIDAS Articles 43-44.
 *
 * Provides non-repudiation evidence that data was sent and received,
 * protecting both sender and recipient against denial of transmission
 * or receipt.
 */
#[Api(since: '1.0.0')]
interface RegisteredDeliveryServiceInterface
{
    /**
     * Send data via registered electronic delivery.
     *
     * Creates a delivery receipt with non-repudiation evidence that the
     * data was submitted for delivery.
     *
     * @param string $data      The data to deliver
     * @param string $sender    Sender identity
     * @param string $recipient Recipient identity
     *
     * @return DeliveryReceipt Receipt proving submission
     */
    public function send(string $data, string $sender, string $recipient): DeliveryReceipt;

    /**
     * Confirm delivery and obtain proof of receipt.
     *
     * @param string $messageId The message identifier from the original receipt
     *
     * @return DeliveryReceipt Updated receipt with delivery confirmation
     */
    public function confirmDelivery(string $messageId): DeliveryReceipt;

    /**
     * Retrieve the delivery receipt for a previously sent message.
     *
     * @param string $messageId The message identifier
     *
     * @return DeliveryReceipt|null The receipt, or null if not found
     */
    public function getReceipt(string $messageId): ?DeliveryReceipt;
}
