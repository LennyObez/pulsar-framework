<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Commerce;

use Pulsar\Extension\Cms\Commerce\Order;

/**
 * Dispatched when an order checkout is cancelled.
 *
 * @psalm-api Dispatched via the EventDispatcher; consumed by listener
 *            classes resolved by event name, not instantiated by name.
 */
final readonly class OrderCancelledEvent
{
    public function __construct(
        public Order $order,
    ) {}
}
