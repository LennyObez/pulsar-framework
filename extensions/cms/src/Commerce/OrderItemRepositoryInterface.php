<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * Repository interface for order line item persistence.
 * @api
 */
#[Api(since: '1.0.0')]
interface OrderItemRepositoryInterface
{
    /**
     * @return list<OrderItem>
     */
    public function findByOrder(string $orderId): array;

    public function save(OrderItem $item): void;
}
