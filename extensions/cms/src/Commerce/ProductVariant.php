<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * A purchasable variant of a product (e.g. size, color combination).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ProductVariant
{
    /**
     * @param string $id UUIDv7
     * @param string $productId UUIDv7 of the parent product
     * @param string $skuSuffix Appended to the product SKU to form the variant SKU
     * @param array<string, string> $attributeValues Map of attribute key to selected value
     * @param int $priceModifier Price adjustment in minor currency units (can be negative)
     * @param int $stockQuantity Available inventory for this variant
     * @param string|null $mediaAssetId UUIDv7 of the associated media asset
     * @param int $sortOrder Display ordering among siblings
     * @param bool $isActive Whether this variant is available for purchase
     */
    public function __construct(
        public string $id,
        public string $productId,
        public string $skuSuffix,
        public array $attributeValues,
        public int $priceModifier,
        public int $stockQuantity,
        public ?string $mediaAssetId,
        public int $sortOrder,
        public bool $isActive,
    ) {}
}
