<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Commerce;

use Pulsar\Extension\Cms\Commerce\Order;

/**
 * Dispatched when a refund is processed for an order.
 */
final readonly class RefundProcessedEvent
{
    public function __construct(
        public Order $order,
        public int $refundAmount,
        public string $reason,
    ) {}
}
