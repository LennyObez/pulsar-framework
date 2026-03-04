<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Domain;

use Pulsar\Api\Api;

/**
 * A single item within an e-commerce transaction.
 */
#[Api(since: '1.0.0')]
final readonly class EcommerceItem
{
    public function __construct(
        public string $productId,
        public string $name,
        public string $category = '',
        public float $price = 0.0,
        public int $quantity = 1,
        public string $variant = '',
    ) {}
}
