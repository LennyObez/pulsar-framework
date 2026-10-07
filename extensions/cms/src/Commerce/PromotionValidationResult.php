<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * Result of validating a coupon code against the current cart.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PromotionValidationResult
{
    /**
     * @param bool $isValid Whether the coupon is valid for the given cart
     * @param Promotion|null $promotion The resolved promotion, if valid
     * @param list<string> $errors Validation error messages
     */
    public function __construct(
        public bool $isValid,
        public ?Promotion $promotion,
        public array $errors,
    ) {}
}
