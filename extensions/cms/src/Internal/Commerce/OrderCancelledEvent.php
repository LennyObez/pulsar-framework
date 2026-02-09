<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Commerce;

use Pulsar\Extension\Cms\Commerce\Order;

/**
 * Dispatched when an order checkout is cancelled.
 */
final readonly class OrderCancelledEvent
{
    public function __construct(
        public Order $order,
    ) {}
}
