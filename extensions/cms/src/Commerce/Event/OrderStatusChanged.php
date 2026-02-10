<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce\Event;

use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Commerce\OrderStatus;

/**
 * Dispatched when an order transitions to a new status.
 */
#[Api(since: '1.0.0')]
final readonly class OrderStatusChanged
{
    public function __construct(
        public string $orderId,
        public OrderStatus $previousStatus,
        public OrderStatus $newStatus,
        public ?string $reason,
    ) {}
}
