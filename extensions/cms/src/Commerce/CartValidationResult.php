<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * Result of cart validation with current prices and error details.
 */
#[Api(since: '1.0.0')]
final readonly class CartValidationResult
{
    /**
     * @param bool $isValid Whether all cart items passed validation
     * @param list<string> $errors Validation error messages
     * @param list<array{productId: string, quantity: int, unitPrice: int, currency: string, variantId?: string|null}> $validatedItems Items with current verified prices
     */
    public function __construct(
        public bool $isValid,
        public array $errors,
        public array $validatedItems,
    ) {}
}
