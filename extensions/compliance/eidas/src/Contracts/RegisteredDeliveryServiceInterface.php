<?php

declare(strict_types=1);

namespace Pulsar\Extension\Eidas\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Eidas\Domain\DeliveryReceipt;
use Pulsar\Extension\Eidas\Exception\EidasException;

/**
 * Registered electronic delivery service per eIDAS Art. 43-44.
 *
 * Provides evidence of transmission and receipt with non-repudiation.
 */
#[Api(since: '1.0.0')]
interface RegisteredDeliveryServiceInterface
{
    /**
     * Send data via registered delivery.
     *
     * @param string $sender Sender identifier
     * @param string $recipient Recipient identifier
     * @param string $content The content to deliver
     *
     * @throws EidasException On delivery failure
     */
    public function send(string $sender, string $recipient, string $content): DeliveryReceipt;

    /**
     * Confirm delivery of a previously sent message.
     *
     * @param string $receiptId The receipt ID from the original send
     *
     * @throws EidasException If receipt not found
     */
    public function confirmDelivery(string $receiptId): DeliveryReceipt;

    /**
     * Retrieve a delivery receipt by ID.
     */
    public function getReceipt(string $receiptId): ?DeliveryReceipt;
}
