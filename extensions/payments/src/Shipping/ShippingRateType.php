<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Shipping;

use Pulsar\Api\Api;

/**
 * Shipping rate calculation strategies.
 * @api
 */
#[Api(since: '1.0.0')]
enum ShippingRateType: string
{
    /** Fixed flat rate regardless of order size or weight. */
    case Flat = 'flat';

    /** Rate varies by total order weight. */
    case Weight = 'weight';

    /** Rate varies by order subtotal price tiers. */
    case Price = 'price';

    /** Base rate plus an additional charge per item. */
    case PerItem = 'per_item';

    /** Free shipping when order subtotal exceeds a threshold. */
    case FreeAbove = 'free_above';
}
