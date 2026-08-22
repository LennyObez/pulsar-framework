<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * A single line item within an order.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class OrderItem
{
    /**
     * @param string $id UUIDv7
     * @param string $orderId UUIDv7 of the parent order
     * @param string $productId UUIDv7 of the purchased product
     * @param string|null $variantId UUIDv7 of the purchased variant, null if no variant
     * @param int $quantity Number of units ordered
     * @param int $unitPrice Price per unit in minor currency units
     * @param int $totalPrice Total line price in minor currency units (quantity * unitPrice)
     * @param int $taxAmount Tax for this line item in minor currency units
     * @param int $discountAmount Discount applied to this line item in minor currency units
     * @param array<string, mixed> $productSnapshot Point-in-time product data for historical record
     */
    public function __construct(
        public string $id,
        public string $orderId,
        public string $productId,
        public ?string $variantId,
        public int $quantity,
        public int $unitPrice,
        public int $totalPrice,
        public int $taxAmount,
        public int $discountAmount,
        public array $productSnapshot,
    ) {}
}
