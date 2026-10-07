<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Commerce;

use Pulsar\Extension\Cms\Commerce\Order;
use Pulsar\Extension\Cms\Commerce\OrderStatus;

/**
 * Dispatched when an order transitions to a new status.
 *
 * @psalm-api Dispatched via the EventDispatcher; consumed by listener
 *            classes resolved by event name, not instantiated by name.
 */
final readonly class OrderStatusChangedEvent
{
    public function __construct(
        public Order $order,
        public OrderStatus $newStatus,
    ) {}
}
