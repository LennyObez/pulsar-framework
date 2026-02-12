<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Product aggregate root representing a purchasable item in the commerce catalog.
 */
#[Api(since: '1.0.0')]
final readonly class Product
{
    /**
     * @param string $id UUIDv7
     * @param string|null $tenantId UUIDv7, nullable when tenancy disabled
     * @param string $sku Stock keeping unit
     * @param ProductStatus $status Current product lifecycle status
     * @param int $priceAmount Price in minor currency units (e.g. cents)
     * @param string $priceCurrency ISO 4217 currency code
     * @param string|null $taxCategory Tax category identifier for tax calculation
     * @param int $stockQuantity Available inventory count
     * @param bool $digital Whether this is a digital product
     * @param string|null $contentId Associated CMS content ID for product description pages
     * @param DateTimeImmutable $createdAt Immutable creation timestamp
     * @param DateTimeImmutable $updatedAt Auto-managed update timestamp
     */
    public function __construct(
        public string $id,
        public ?string $tenantId,
        public string $sku,
        public ProductStatus $status,
        public int $priceAmount,
        public string $priceCurrency,
        public ?string $taxCategory,
        public int $stockQuantity,
        public bool $digital,
        public ?string $contentId,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    /**
     * Create a new draft product.
     */
    public static function create(
        string $id,
        string $sku,
        int $priceAmount,
        string $priceCurrency,
        ?string $tenantId = null,
        ?string $taxCategory = null,
        int $stockQuantity = 0,
        bool $digital = false,
        ?string $contentId = null,
    ): self {
        $now = new DateTimeImmutable();

        return new self(
            id: $id,
            tenantId: $tenantId,
            sku: $sku,
            status: ProductStatus::Draft,
            priceAmount: $priceAmount,
            priceCurrency: $priceCurrency,
            taxCategory: $taxCategory,
            stockQuantity: $stockQuantity,
            digital: $digital,
            contentId: $contentId,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    public function isActive(): bool
    {
        return $this->status === ProductStatus::Active;
    }

    public function isDigital(): bool
    {
        return $this->digital;
    }

    public function isInStock(): bool
    {
        return $this->stockQuantity > 0;
    }
}
