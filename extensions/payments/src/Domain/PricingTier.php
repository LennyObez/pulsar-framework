<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Domain;

use Pulsar\Api\Api;

/**
 * A tier within a tiered pricing plan.
 */
#[Api(since: '1.0.0')]
final readonly class PricingTier
{
    public function __construct(
        public int $upTo,
        public Money $unitPrice,
        public ?Money $flatFee,
    ) {}
}
