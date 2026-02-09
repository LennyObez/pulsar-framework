<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Commerce;

use Pulsar\Extension\Cms\Commerce\Order;

/**
 * Dispatched when payment is successfully received for an order.
 */
final readonly class PaymentReceivedEvent
{
    public function __construct(
        public Order $order,
        public ?string $paymentIntentId,
    ) {}
}
