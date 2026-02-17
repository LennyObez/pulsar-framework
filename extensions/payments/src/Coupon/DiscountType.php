<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Coupon;

use Pulsar\Api\Api;

/**
 * Types of discounts that can be applied.
 */
#[Api(since: '1.0.0')]
enum DiscountType: string
{
    /** Percentage discount in basis points (e.g., 2000 = 20% off). */
    case Percentage = 'percentage';

    /** Fixed amount discount in minor currency units (e.g., 1000 = $10.00 off). */
    case FixedAmount = 'fixed_amount';

    /** Free trial extension in days. */
    case FreeTrial = 'free_trial';
}
