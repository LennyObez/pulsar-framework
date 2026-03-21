<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Domain;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Immutable invoice line item.
 *
 * Supports optional per-line tax classification for EN 16931 e-invoicing
 * compliance. The taxCategory and taxRatePercent fields map directly to
 * UBL ClassifiedTaxCategory.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class InvoiceLineItem
{
    /**
     * @param string $description Line item description
     * @param int $quantity Item quantity
     * @param Money $unitPrice Unit price in minor currency units
     * @param Money $total Line total (quantity * unitPrice)
     * @param string|null $taxCategory EN 16931 tax category code (e.g., "S", "Z", "E")
     * @param int|null $taxRatePercent Tax rate in basis points (e.g., 2100 = 21%)
     */
    public function __construct(
        public string $description,
        public int $quantity,
        public Money $unitPrice,
        public Money $total,
        public ?string $taxCategory = null,
        public ?int $taxRatePercent = null,
    ) {}

    /**
     * Create a line item and compute the total.
     */
    #[NoDiscard]
    public static function create(
        string $description,
        int $quantity,
        Money $unitPrice,
        ?string $taxCategory = null,
        ?int $taxRatePercent = null,
    ): self {
        return new self(
            description: $description,
            quantity: $quantity,
            unitPrice: $unitPrice,
            total: $unitPrice->multiply($quantity),
            taxCategory: $taxCategory,
            taxRatePercent: $taxRatePercent,
        );
    }
}
