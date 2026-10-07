<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when a new order is created.
 *
 * @psalm-api Event class — dispatched by the order workflow through
 *            the EventDispatcher.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class OrderCreated
{
    public function __construct(
        public string $orderId,
        public string $orderNumber,
        public string $customerId,
    ) {}
}
