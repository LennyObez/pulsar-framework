<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Cart;

use Pulsar\Api\Api;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;

/**
 * Immutable cart line item representing a product with quantity and pricing.
 *
 * All monetary values use the {@see Money} value object with integer
 * minor units to avoid floating-point precision issues.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CartItem
{
    /**
     * @param non-empty-string $id Unique identifier for this cart line
     * @param non-empty-string $productId Reference to the catalog product
     * @param string $productName Human-readable product name for display
     * @param int<1, max> $quantity Number of units (always >= 1)
     * @param Money $unitPrice Price per unit in minor currency units
     * @param array<string, mixed> $metadata Arbitrary key-value data (variant, color, size, etc.)
     */
    public function __construct(
        public string $id,
        public string $productId,
        public string $productName,
        public int $quantity,
        public Money $unitPrice,
        public array $metadata = [],
    ) {}

    /**
     * Calculate the line total (unitPrice * quantity).
     */
    public function lineTotal(): Money
    {
        return $this->unitPrice->multiply($this->quantity);
    }

    /**
     * Return a new CartItem with an updated quantity.
     *
     * @param int<1, max> $quantity
     */
    public function withQuantity(int $quantity): static
    {
        return clone($this, ['quantity' => $quantity]);
    }

    /**
     * Serialize this item to a storage-friendly array.
     *
     * @return array{id: non-empty-string, productId: non-empty-string, productName: string, quantity: int<1, max>, unitPriceAmount: int, currency: string, metadata: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'productId' => $this->productId,
            'productName' => $this->productName,
            'quantity' => $this->quantity,
            'unitPriceAmount' => $this->unitPrice->amount,
            'currency' => $this->unitPrice->currency->value,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Reconstruct a CartItem from its serialized array form.
     *
     * @param array{id: non-empty-string, productId: non-empty-string, productName: string, quantity: int<1, max>, unitPriceAmount: int, currency: string, metadata?: array<string, mixed>} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            productId: $data['productId'],
            productName: $data['productName'],
            quantity: $data['quantity'],
            unitPrice: Money::of($data['unitPriceAmount'], Currency::from($data['currency'])),
            metadata: $data['metadata'] ?? [],
        );
    }
}
