<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Commerce;

use Pulsar\Extension\Cms\Commerce\Order;

/**
 * Dispatched when a new order is created during checkout.
 */
final readonly class OrderCreatedEvent
{
    public function __construct(
        public Order $order,
    ) {}
}
