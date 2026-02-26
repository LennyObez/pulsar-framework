<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when a payment is successfully processed for an order.
 */
#[Api(since: '1.0.0')]
final readonly class PaymentReceived
{
    public function __construct(
        public string $orderId,
        public int $amount,
        public string $paymentIntentId,
    ) {}
}
